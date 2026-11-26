<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ClubeTemporadaRepositoryInterface;
use App\Contracts\MonteCarloSimulatorInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * Orquestra o cálculo probabilístico da temporada e consolida métricas
 * de zonas/título (#12), distribuição de posições 1º–20º (#13) e persistência (#41).
 *
 * Premissas e limitações do modelo:
 * - Os valores produzidos são estimativas estatísticas baseadas em 2.000 simulações
 *   Monte Carlo a partir do estado atual da competição e partidas restantes.
 * - Não contemplam variáveis extracampo (lesões, suspensões, troca de técnico ou
 *   possíveis vagas extras herdadas por campeões de copas continentais/nacionais).
 * - Cada posição na distribuição 1º–20º é arredondada independentemente para 2 casas
 *   decimais; portanto, a soma por clube é aproximadamente 100.00% (tolerância de ±0.10%).
 * - As probabilidades por zona (campeão, Libertadores, Sul-Americana, rebaixamento)
 *   são métricas independentes e não devem ser somadas entre si.
 */
class ProbabilidadeService
{
    public const DEFAULT_ITERATIONS = 2000;
    public const TOTAL_POSITIONS = 20;
    public const POSITION_SUM_TOLERANCE = 0.10;

    /**
     * Configuração padrão das faixas de classificação do Brasileirão Série A (1º a 20º).
     * Pode ser sobrescrita via construtor sem alterar o núcleo do cálculo.
     *
     * @var array<string, array{min: int, max: int}>
     */
    public const DEFAULT_ZONE_RULES = [
        'campeao' => ['min' => 1, 'max' => 1],
        'libertadores' => ['min' => 1, 'max' => 6],
        'sulamericana' => ['min' => 7, 'max' => 12],
        'rebaixamento' => ['min' => 17, 'max' => 20],
    ];

    /** @var array<string, array{min: int, max: int}> */
    private array $zoneRules;

    /**
     * @param array<string, array{min: int, max: int}>|null $zoneRules
     */
    public function __construct(
        private ?MonteCarloSimulatorInterface $simulator = null,
        private ?StandingsService $standingsService = null,
        private ?ClubeTemporadaRepositoryInterface $clubeTemporadaRepository = null,
        private ?PDO $pdo = null,
        ?array $zoneRules = null
    ) {
        $this->zoneRules = $this->validateZoneRules($zoneRules ?? self::DEFAULT_ZONE_RULES);
    }

    /**
     * Retorna as regras de faixa de posição configuradas para cada zona.
     *
     * @return array<string, array{min: int, max: int}>
     */
    public function getZoneRules(): array
    {
        return $this->zoneRules;
    }

    /**
     * Issue #12: Deriva probabilidades independentes de título e zonas por clube
     * a partir das iterações do motor Monte Carlo.
     *
     * Retorna array indexado por `clube_id`. Quando não houver iterações válidas,
     * retorna [] (ou mantém campos NULL por clube quando clubes base forem informados).
     *
     * @param array<int, array<int|string, mixed>> $simulationResults
     * @param array<int, int> $clubeIds Lista opcional de clubes esperados para preservar NULL se indisponível
     * @return array<int, array{
     *     campeao: float|null,
     *     libertadores: float|null,
     *     sulamericana: float|null,
     *     rebaixamento: float|null
     * }>
     */
    public function calculateZoneProbabilities(array $simulationResults, array $clubeIds = []): array
    {
        $validIterations = $this->normalizeSimulationIterations($simulationResults);
        $totalValid = count($validIterations);

        if ($totalValid === 0) {
            return $this->buildNullZonesMap($clubeIds);
        }

        $discoveredClubs = $clubeIds;
        if ($discoveredClubs === []) {
            $discoveredClubs = array_keys($validIterations[0]);
        }

        $counts = [];
        foreach ($discoveredClubs as $clubeId) {
            $id = (int) $clubeId;
            $counts[$id] = [
                'campeao' => 0,
                'libertadores' => 0,
                'sulamericana' => 0,
                'rebaixamento' => 0,
            ];
        }

        foreach ($validIterations as $rankingByClub) {
            foreach ($counts as $clubeId => $_) {
                if (!isset($rankingByClub[$clubeId])) {
                    continue;
                }
                $posicao = $rankingByClub[$clubeId];

                foreach ($this->zoneRules as $zoneKey => $bounds) {
                    if ($posicao >= $bounds['min'] && $posicao <= $bounds['max']) {
                        $counts[$clubeId][$zoneKey]++;
                    }
                }
            }
        }

        $probabilities = [];
        foreach ($counts as $clubeId => $zoneCounts) {
            $probabilities[$clubeId] = [
                'campeao' => $this->toPercentage($zoneCounts['campeao'], $totalValid),
                'libertadores' => $this->toPercentage($zoneCounts['libertadores'], $totalValid),
                'sulamericana' => $this->toPercentage($zoneCounts['sulamericana'], $totalValid),
                'rebaixamento' => $this->toPercentage($zoneCounts['rebaixamento'], $totalValid),
            ];
        }

        return $probabilities;
    }

    /**
     * Issue #13: Transforma classificações simuladas em distribuição percentual
     * das posições finais ("1" a "20") por clube.
     *
     * Se não houver iterações válidas, retorna [] (ou NULL por clube se $clubeIds for informado),
     * sem fabricar vetor de zeros.
     *
     * @param array<int, array<int|string, mixed>> $simulationResults
     * @param array<int, int> $clubeIds
     * @return array<int, array<string, float>|null>
     */
    public function buildPositionDistribution(array $simulationResults, array $clubeIds = []): array
    {
        $validIterations = $this->normalizeSimulationIterations($simulationResults);
        $totalValid = count($validIterations);

        if ($totalValid === 0) {
            $empty = [];
            foreach ($clubeIds as $clubeId) {
                $empty[(int) $clubeId] = null;
            }
            return $empty;
        }

        $discoveredClubs = $clubeIds;
        if ($discoveredClubs === []) {
            $discoveredClubs = array_keys($validIterations[0]);
        }

        $counts = [];
        foreach ($discoveredClubs as $clubeId) {
            $id = (int) $clubeId;
            $counts[$id] = array_fill(1, self::TOTAL_POSITIONS, 0);
        }

        foreach ($validIterations as $rankingByClub) {
            foreach ($counts as $clubeId => $_) {
                if (!isset($rankingByClub[$clubeId])) {
                    continue;
                }
                $posicao = $rankingByClub[$clubeId];
                if ($posicao >= 1 && $posicao <= self::TOTAL_POSITIONS) {
                    $counts[$clubeId][$posicao]++;
                }
            }
        }

        $distributions = [];
        foreach ($counts as $clubeId => $posCounts) {
            $dist = [];
            for ($pos = 1; $pos <= self::TOTAL_POSITIONS; $pos++) {
                $dist[(string) $pos] = $this->toPercentage($posCounts[$pos], $totalValid);
            }
            $this->assertValidDistribution($dist, $clubeId);
            $distributions[$clubeId] = $dist;
        }

        return $distributions;
    }

    /**
     * Issue #41: Orquestra o cálculo probabilístico completo da temporada e persiste
     * os resultados em `clubes_temporadas`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function calculateSeason(int $temporadaId, int $iterations = self::DEFAULT_ITERATIONS): array
    {
        if ($temporadaId <= 0) {
            throw new InvalidArgumentException('temporadaId deve ser um inteiro positivo.');
        }

        if ($iterations <= 0) {
            throw new InvalidArgumentException('iterations deve ser um inteiro positivo.');
        }

        if ($this->simulator === null || $this->standingsService === null || $this->clubeTemporadaRepository === null) {
            throw new LogicException(
                'Dependências obrigatórias ausentes: MonteCarloSimulatorInterface, StandingsService e ClubeTemporadaRepositoryInterface.'
            );
        }

        $clubesTemporada = $this->clubeTemporadaRepository->findByTemporada($temporadaId);
        if ($clubesTemporada === []) {
            return [];
        }

        $clubeIds = array_map(
            static fn (array $row): int => (int) $row['clube_id'],
            $clubesTemporada
        );

        $standings = $this->loadCurrentStandings($temporadaId, $clubesTemporada);
        if ($standings === []) {
            $unavailable = $this->buildUnavailableSeasonResults($clubeIds);
            $this->persistResults($temporadaId, $unavailable);
            return $unavailable;
        }

        $remainingMatches = $this->loadRemainingMatches($temporadaId);
        $simulationResults = $this->simulator->simulate($standings, $remainingMatches, $iterations);

        $zoneProbabilities = $this->calculateZoneProbabilities($simulationResults, $clubeIds);
        $positionDistributions = $this->buildPositionDistribution($simulationResults, $clubeIds);

        $hasValidData = false;
        foreach ($clubeIds as $clubeId) {
            if (($positionDistributions[$clubeId] ?? null) !== null) {
                $hasValidData = true;
                break;
            }
        }

        if (!$hasValidData) {
            $unavailable = $this->buildUnavailableSeasonResults($clubeIds);
            $this->persistResults($temporadaId, $unavailable);
            return $unavailable;
        }

        $calculatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $results = [];

        foreach ($clubeIds as $clubeId) {
            $zones = $zoneProbabilities[$clubeId] ?? [
                'campeao' => null,
                'libertadores' => null,
                'sulamericana' => null,
                'rebaixamento' => null,
            ];
            $positions = $positionDistributions[$clubeId] ?? null;

            $this->validateClubResult($zones, $positions, $clubeId);

            $results[$clubeId] = [
                'clube_id' => $clubeId,
                'temporada_id' => $temporadaId,
                'probabilidade_campeao' => $zones['campeao'],
                'probabilidade_libertadores' => $zones['libertadores'],
                'probabilidade_sulamericana' => $zones['sulamericana'],
                'probabilidade_rebaixamento' => $zones['rebaixamento'],
                'probabilidades_posicoes' => $positions,
                'probabilidades_calculadas_em' => $positions !== null ? $calculatedAt : null,
            ];
        }

        $this->persistResults($temporadaId, $results);

        return $results;
    }

    /**
     * Consulta as probabilidades persistidas de um clube na temporada sem reexecutar Monte Carlo.
     *
     * @return array<string, mixed>
     */
    public function getClubProbabilities(int $temporadaId, int $clubeId): array
    {
        if ($this->clubeTemporadaRepository === null) {
            throw new LogicException('ClubeTemporadaRepositoryInterface não configurado.');
        }

        $record = $this->clubeTemporadaRepository->findByTemporadaAndClube($temporadaId, $clubeId);
        if ($record === null) {
            return [
                'temporada_id' => $temporadaId,
                'clube_id' => $clubeId,
                'disponivel' => false,
                'estimativa' => true,
                'probabilidade_campeao' => null,
                'probabilidade_libertadores' => null,
                'probabilidade_sulamericana' => null,
                'probabilidade_rebaixamento' => null,
                'probabilidades_posicoes' => null,
                'probabilidades_calculadas_em' => null,
            ];
        }

        $isAvailable = $record['probabilidades_calculadas_em'] !== null
            && $record['probabilidades_posicoes'] !== null;

        return [
            'temporada_id' => $temporadaId,
            'clube_id' => $clubeId,
            'disponivel' => $isAvailable,
            'estimativa' => true,
            'probabilidade_campeao' => $isAvailable ? $record['probabilidade_campeao'] : null,
            'probabilidade_libertadores' => $isAvailable ? $record['probabilidade_libertadores'] : null,
            'probabilidade_sulamericana' => $isAvailable ? $record['probabilidade_sulamericana'] : null,
            'probabilidade_rebaixamento' => $isAvailable ? $record['probabilidade_rebaixamento'] : null,
            'probabilidades_posicoes' => $isAvailable ? $record['probabilidades_posicoes'] : null,
            'probabilidades_calculadas_em' => $isAvailable ? $record['probabilidades_calculadas_em'] : null,
        ];
    }

    /**
     * Issue #41: Valida e persiste os resultados calculados por clube/temporada.
     *
     * @param array<int, array<string, mixed>> $results
     */
    public function persistResults(int $temporadaId, array $results): void
    {
        if ($this->clubeTemporadaRepository === null) {
            throw new LogicException('ClubeTemporadaRepositoryInterface não configurado.');
        }

        foreach ($results as $key => $result) {
            $clubeId = (int) ($result['clube_id'] ?? $key);
            if ($clubeId <= 0) {
                throw new InvalidArgumentException('Identificador de clube inválido ao persistir probabilidades.');
            }

            $zones = [
                'campeao' => $result['probabilidade_campeao'] ?? null,
                'libertadores' => $result['probabilidade_libertadores'] ?? null,
                'sulamericana' => $result['probabilidade_sulamericana'] ?? null,
                'rebaixamento' => $result['probabilidade_rebaixamento'] ?? null,
            ];
            $positions = $result['probabilidades_posicoes'] ?? null;

            $this->validateClubResult($zones, $positions, $clubeId);

            $this->clubeTemporadaRepository->upsertProbabilities($temporadaId, $clubeId, [
                'probabilidade_campeao' => $zones['campeao'],
                'probabilidade_libertadores' => $zones['libertadores'],
                'probabilidade_sulamericana' => $zones['sulamericana'],
                'probabilidade_rebaixamento' => $zones['rebaixamento'],
                'probabilidades_posicoes' => $positions,
                'probabilidades_calculadas_em' => $result['probabilidades_calculadas_em'] ?? null,
            ]);
        }
    }

    /**
     * Normaliza e filtra iterações válidas vindas do simulador.
     * Aceita tanto lista de linhas `[['clube_id' => 1, 'posicao' => 1], ...]`
     * quanto mapa direto `[clube_id => posicao]`.
     *
     * @param array<int, mixed> $simulationResults
     * @return array<int, array<int, int>>
     */
    private function normalizeSimulationIterations(array $simulationResults): array
    {
        $normalized = [];

        foreach ($simulationResults as $iteration) {
            if (!is_array($iteration) || $iteration === []) {
                continue;
            }

            $map = [];
            $valid = true;

            foreach ($iteration as $key => $value) {
                if (is_array($value)) {
                    if (!isset($value['clube_id'], $value['posicao'])) {
                        $valid = false;
                        break;
                    }
                    $clubeId = (int) $value['clube_id'];
                    $posicao = (int) $value['posicao'];
                } elseif (is_numeric($key) && is_numeric($value)) {
                    $clubeId = (int) $key;
                    $posicao = (int) $value;
                } else {
                    $valid = false;
                    break;
                }

                if ($clubeId <= 0 || $posicao < 1 || $posicao > self::TOTAL_POSITIONS) {
                    $valid = false;
                    break;
                }

                $map[$clubeId] = $posicao;
            }

            if ($valid && $map !== []) {
                $normalized[] = $map;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, array{min: int, max: int}> $rules
     * @return array<string, array{min: int, max: int}>
     */
    private function validateZoneRules(array $rules): array
    {
        $requiredKeys = ['campeao', 'libertadores', 'sulamericana', 'rebaixamento'];
        foreach ($requiredKeys as $key) {
            if (!isset($rules[$key]['min'], $rules[$key]['max'])) {
                throw new InvalidArgumentException("Regra de zona ausente ou inválida para '{$key}'.");
            }

            $min = (int) $rules[$key]['min'];
            $max = (int) $rules[$key]['max'];

            if ($min < 1 || $max > self::TOTAL_POSITIONS || $min > $max) {
                throw new InvalidArgumentException("Faixa de posição inválida para zona '{$key}': {$min}..{$max}.");
            }
        }

        return $rules;
    }

    private function toPercentage(int $occurrences, int $totalIterations): float
    {
        if ($totalIterations <= 0) {
            return 0.0;
        }

        $value = round(($occurrences / $totalIterations) * 100, 2);
        return max(0.0, min(100.0, $value));
    }

    /**
     * @param array<string, float> $distribution
     */
    private function assertValidDistribution(array $distribution, int $clubeId): void
    {
        if (count($distribution) !== self::TOTAL_POSITIONS) {
            throw new InvalidArgumentException(
                "Distribuição de posições do clube {$clubeId} deve conter exatamente 20 chaves."
            );
        }

        $sum = 0.0;
        for ($pos = 1; $pos <= self::TOTAL_POSITIONS; $pos++) {
            $key = (string) $pos;
            if (!array_key_exists($key, $distribution)) {
                throw new InvalidArgumentException("Chave de posição '{$key}' ausente para clube {$clubeId}.");
            }

            $val = (float) $distribution[$key];
            if ($val < 0.0 || $val > 100.0) {
                throw new InvalidArgumentException("Percentual fora da faixa 0..100 na posição '{$key}' do clube {$clubeId}.");
            }
            $sum += $val;
        }

        if (abs($sum - 100.0) > self::POSITION_SUM_TOLERANCE) {
            throw new InvalidArgumentException(
                sprintf('Soma das posições do clube %d (%.2f%%) excede a tolerância permitida de 100%%.', $clubeId, $sum)
            );
        }
    }

    /**
     * @param array{
     *     campeao: float|null,
     *     libertadores: float|null,
     *     sulamericana: float|null,
     *     rebaixamento: float|null
     * } $zones
     * @param array<string, float>|null $positions
     */
    private function validateClubResult(array $zones, ?array $positions, int $clubeId): void
    {
        foreach ($zones as $zoneName => $value) {
            if ($value === null) {
                continue;
            }
            if ($value < 0.0 || $value > 100.0) {
                throw new InvalidArgumentException(
                    "Probabilidade '{$zoneName}' fora da faixa 0.00..100.00 para o clube {$clubeId}."
                );
            }
        }

        if ($positions !== null) {
            $this->assertValidDistribution($positions, $clubeId);
        }
    }

    /**
     * @param array<int, int> $clubeIds
     * @return array<int, array{
     *     campeao: null,
     *     libertadores: null,
     *     sulamericana: null,
     *     rebaixamento: null
     * }>
     */
    private function buildNullZonesMap(array $clubeIds): array
    {
        $map = [];
        foreach ($clubeIds as $clubeId) {
            $map[(int) $clubeId] = [
                'campeao' => null,
                'libertadores' => null,
                'sulamericana' => null,
                'rebaixamento' => null,
            ];
        }
        return $map;
    }

    /**
     * @param array<int, int> $clubeIds
     * @return array<int, array<string, mixed>>
     */
    private function buildUnavailableSeasonResults(array $clubeIds): array
    {
        $results = [];
        foreach ($clubeIds as $clubeId) {
            $id = (int) $clubeId;
            $results[$id] = [
                'clube_id' => $id,
                'probabilidade_campeao' => null,
                'probabilidade_libertadores' => null,
                'probabilidade_sulamericana' => null,
                'probabilidade_rebaixamento' => null,
                'probabilidades_posicoes' => null,
                'probabilidades_calculadas_em' => null,
            ];
        }
        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $clubesTemporada
     * @return array<int, array<string, mixed>>
     */
    private function loadCurrentStandings(int $temporadaId, array $clubesTemporada): array
    {
        if ($this->pdo !== null) {
            return $this->standingsService->getStandingsByTemporada($temporadaId);
        }

        $clubes = array_map(static fn (array $row): array => [
            'id' => (int) $row['clube_id'],
            'external_id' => (int) ($row['external_id'] ?? 0),
            'nome' => (string) ($row['nome'] ?? ''),
            'nome_curto' => (string) ($row['nome_curto'] ?? ''),
            'sigla' => (string) ($row['sigla'] ?? ''),
            'escudo_url' => $row['escudo_url'] ?? null,
            'ajuste_pontos' => (int) ($row['ajuste_pontos'] ?? 0),
        ], $clubesTemporada);

        return $this->standingsService->calculateFromData($clubes, []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRemainingMatches(int $temporadaId): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, temporada_id, rodada, data_hora_utc, status, mandante_id, visitante_id
               FROM partidas
              WHERE temporada_id = :temporada_id
                AND status NOT IN ('FINISHED', 'AWARDED')
              ORDER BY rodada ASC, data_hora_utc ASC"
        );
        $stmt->execute([':temporada_id' => $temporadaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}