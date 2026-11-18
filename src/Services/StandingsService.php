<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Calcula a classificação oficial do Brasileirão Série A
 * exclusivamente a partir das partidas persistidas no MySQL.
 */
class StandingsService
{
    /** Status de partidas que contabilizam pontos oficiais. */
    private const VALID_STATUSES = ['FINISHED', 'AWARDED'];

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Obtém a classificação oficial da temporada consultando o MySQL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStandingsByTemporada(int $temporadaId): array
    {
        if ($this->pdo === null) {
            throw new \LogicException('Conexão PDO não fornecida ao StandingsService.');
        }

        $stmtClubes = $this->pdo->prepare(
            'SELECT c.id,
                    c.external_id,
                    c.nome,
                    c.nome_curto,
                    c.sigla,
                    c.escudo_url,
                    COALESCE(ct.ajuste_pontos, 0) AS ajuste_pontos
               FROM clubes c
               INNER JOIN clubes_temporadas ct ON ct.clube_id = c.id
              WHERE ct.temporada_id = :temporada_id'
        );
        $stmtClubes->execute([':temporada_id' => $temporadaId]);
        $clubes = $stmtClubes->fetchAll(PDO::FETCH_ASSOC);

        if ($clubes === []) {
            return [];
        }

        $stmtPartidas = $this->pdo->prepare(
            "SELECT mandante_id,
                    visitante_id,
                    gols_mandante,
                    gols_visitante,
                    status
               FROM partidas
              WHERE temporada_id = :temporada_id
                AND status IN ('FINISHED', 'AWARDED')
                AND gols_mandante IS NOT NULL
                AND gols_visitante IS NOT NULL"
        );
        $stmtPartidas->execute([':temporada_id' => $temporadaId]);
        $partidas = $stmtPartidas->fetchAll(PDO::FETCH_ASSOC);

        return $this->calculateFromData($clubes, $partidas);
    }

    /**
     * Calcula e ordena a tabela a partir de arrays em memória.
     * Reutilizado diretamente pelo motor Monte Carlo (#11).
     *
     * @param array<int, array<string, mixed>> $clubes
     * @param array<int, array<string, mixed>> $partidas
     * @return array<int, array<string, mixed>>
     */
    public function calculateFromData(array $clubes, array $partidas): array
    {
        $tabela = [];

        foreach ($clubes as $clube) {
            $id = (int) $clube['id'];
            $ajuste = (int) ($clube['ajuste_pontos'] ?? 0);

            $tabela[$id] = [
                'clube_id' => $id,
                'external_id' => (int) ($clube['external_id'] ?? 0),
                'nome' => (string) ($clube['nome'] ?? ''),
                'nome_curto' => (string) ($clube['nome_curto'] ?? $clube['nome'] ?? ''),
                'sigla' => (string) ($clube['sigla'] ?? ''),
                'escudo_url' => $clube['escudo_url'] ?? null,
                'ajuste_pontos' => $ajuste,
                'pontos' => $ajuste,
                'jogos' => 0,
                'vitorias' => 0,
                'empates' => 0,
                'derrotas' => 0,
                'gols_pro' => 0,
                'gols_contra' => 0,
                'saldo_gols' => 0,
                'aproveitamento' => 0.0,
            ];
        }

        // Mapa de confronto direto: $h2h[clubeA][clubeB] = ['pontos' => int, 'saldo' => int, 'gols_pro' => int]
        $h2h = [];

        foreach ($partidas as $partida) {
            $status = strtoupper((string) ($partida['status'] ?? ''));
            if (!in_array($status, self::VALID_STATUSES, true)) {
                continue;
            }

            if ($partida['gols_mandante'] === null || $partida['gols_visitante'] === null) {
                continue;
            }

            $mandanteId = (int) $partida['mandante_id'];
            $visitanteId = (int) $partida['visitante_id'];

            if (!isset($tabela[$mandanteId], $tabela[$visitanteId])) {
                continue;
            }

            $golsMandante = (int) $partida['gols_mandante'];
            $golsVisitante = (int) $partida['gols_visitante'];

            $tabela[$mandanteId]['jogos']++;
            $tabela[$visitanteId]['jogos']++;

            $tabela[$mandanteId]['gols_pro'] += $golsMandante;
            $tabela[$mandanteId]['gols_contra'] += $golsVisitante;

            $tabela[$visitanteId]['gols_pro'] += $golsVisitante;
            $tabela[$visitanteId]['gols_contra'] += $golsMandante;

            $this->initH2H($h2h, $mandanteId, $visitanteId);
            $h2h[$mandanteId][$visitanteId]['saldo'] += ($golsMandante - $golsVisitante);
            $h2h[$mandanteId][$visitanteId]['gols_pro'] += $golsMandante;
            $h2h[$visitanteId][$mandanteId]['saldo'] += ($golsVisitante - $golsMandante);
            $h2h[$visitanteId][$mandanteId]['gols_pro'] += $golsVisitante;

            if ($golsMandante > $golsVisitante) {
                $tabela[$mandanteId]['pontos'] += 3;
                $tabela[$mandanteId]['vitorias']++;
                $tabela[$visitanteId]['derrotas']++;
                $h2h[$mandanteId][$visitanteId]['pontos'] += 3;
            } elseif ($golsMandante < $golsVisitante) {
                $tabela[$visitanteId]['pontos'] += 3;
                $tabela[$visitanteId]['vitorias']++;
                $tabela[$mandanteId]['derrotas']++;
                $h2h[$visitanteId][$mandanteId]['pontos'] += 3;
            } else {
                $tabela[$mandanteId]['pontos'] += 1;
                $tabela[$visitanteId]['pontos'] += 1;
                $tabela[$mandanteId]['empates']++;
                $tabela[$visitanteId]['empates']++;
                $h2h[$mandanteId][$visitanteId]['pontos'] += 1;
                $h2h[$visitanteId][$mandanteId]['pontos'] += 1;
            }
        }

        foreach ($tabela as &$row) {
            $row['saldo_gols'] = $row['gols_pro'] - $row['gols_contra'];
            $pontosDisputados = $row['jogos'] * 3;
            $row['aproveitamento'] = $pontosDisputados > 0
                ? round(($row['pontos'] / $pontosDisputados) * 100, 1)
                : 0.0;
        }
        unset($row);

        $lista = array_values($tabela);

        // Critérios oficiais do Brasileirão Série A (CBF):
        // 1. Pontos | 2. Vitórias | 3. Saldo de gols | 4. Gols pró | 5. Confronto direto | 6. Nome curto
        usort($lista, function (array $a, array $b) use ($h2h): int {
            if ($a['pontos'] !== $b['pontos']) {
                return $b['pontos'] <=> $a['pontos'];
            }
            if ($a['vitorias'] !== $b['vitorias']) {
                return $b['vitorias'] <=> $a['vitorias'];
            }
            if ($a['saldo_gols'] !== $b['saldo_gols']) {
                return $b['saldo_gols'] <=> $a['saldo_gols'];
            }
            if ($a['gols_pro'] !== $b['gols_pro']) {
                return $b['gols_pro'] <=> $a['gols_pro'];
            }

            $idA = $a['clube_id'];
            $idB = $b['clube_id'];
            if (isset($h2h[$idA][$idB], $h2h[$idB][$idA])) {
                if ($h2h[$idA][$idB]['pontos'] !== $h2h[$idB][$idA]['pontos']) {
                    return $h2h[$idB][$idA]['pontos'] <=> $h2h[$idA][$idB]['pontos'];
                }
                if ($h2h[$idA][$idB]['saldo'] !== $h2h[$idB][$idA]['saldo']) {
                    return $h2h[$idB][$idA]['saldo'] <=> $h2h[$idA][$idB]['saldo'];
                }
                if ($h2h[$idA][$idB]['gols_pro'] !== $h2h[$idB][$idA]['gols_pro']) {
                    return $h2h[$idB][$idA]['gols_pro'] <=> $h2h[$idA][$idB]['gols_pro'];
                }
            }

            return strcmp($a['nome_curto'], $b['nome_curto']);
        });

        foreach ($lista as $index => &$row) {
            $row['posicao'] = $index + 1;
        }
        unset($row);

        return $lista;
    }

    /**
     * @param array<int, array<int, array<string, int>>> $h2h
     */
    private function initH2H(array &$h2h, int $idA, int $idB): void
    {
        if (!isset($h2h[$idA][$idB])) {
            $h2h[$idA][$idB] = ['pontos' => 0, 'saldo' => 0, 'gols_pro' => 0];
        }
        if (!isset($h2h[$idB][$idA])) {
            $h2h[$idB][$idA] = ['pontos' => 0, 'saldo' => 0, 'gols_pro' => 0];
        }
    }
}
