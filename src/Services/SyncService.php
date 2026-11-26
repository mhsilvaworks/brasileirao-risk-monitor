<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DataProviderInterface;
use App\Infrastructure\FootballData\ApiRequestException;
use App\Repositories\ClubeRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use App\Repositories\SyncLogRepository;

/**
 * Orquestra a sincronização idempotente de dados da football-data.org
 * para o banco de dados respeitando a integridade referencial:
 * competicao -> temporada -> clubes/participações -> partidas,
 * registrando início, conclusão e falhas em sync_logs.
 */
class SyncService
{
    private PDO $pdo;
    private DataProviderInterface $dataProvider;
    private ClubeRepository $clubeRepository;
    private SyncLogRepository $syncLogRepository;
    private Logger $logger;

    /** @var callable|null */
    private $legacyLogger;

    public function __construct(
        PDO $pdo,
        DataProviderInterface $dataProvider,
        ?ClubeRepository $clubeRepository = null,
        ?callable $logger = null,
        ?SyncLogRepository $syncLogRepository = null
    ) {
        $this->pdo = $pdo;
        $this->dataProvider = $dataProvider;
        $this->clubeRepository = $clubeRepository ?? new ClubeRepository($pdo);
        $this->syncLogRepository = $syncLogRepository ?? new SyncLogRepository($pdo);
        $this->logger = new Logger();
        $this->legacyLogger = $logger;
    }

    /**
     * Executa o fluxo completo de sincronização para uma competição e ano de temporada.
     *
     * @return array<string, mixed>
     */
    public function syncSeason(string $competitionCode = 'BSA', ?int $seasonYear = null): array
    {
        $code = strtoupper(trim($competitionCode));
        if ($code === '') {
            throw new InvalidArgumentException('O código da competição não pode ser vazio.');
        }

        $inicioUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $inicioMicro = microtime(true);
        $logId = $this->iniciarSyncLog('sincronizacao', $inicioUtc, [
            'competition_code' => $code,
            'season_year' => $seasonYear,
        ]);

        $detalhes = [
            'competition_code' => $code,
            'season_year' => $seasonYear,
            'etapas' => [],
        ];
        $registrosProcessados = 0;

        try {
            // 1. Competição
            $competitionPayload = $this->dataProvider->getCompetition($code);
            $competicaoId = $this->upsertCompeticao($competitionPayload);
            $registrosProcessados++;
            $detalhes['etapas']['competicao'] = ['status' => 'ok', 'id' => $competicaoId];

            // 2. Temporada
            $seasonData = $this->resolveSeasonPayload($competitionPayload, $seasonYear);
            $resolvedYear = (int) substr((string) $seasonData['startDate'], 0, 4);
            $detalhes['season_year'] = $resolvedYear;

            $temporadaId = $this->upsertTemporada($competicaoId, $seasonData, $competitionPayload['lastUpdated'] ?? null);
            $registrosProcessados++;
            $detalhes['etapas']['temporada'] = [
                'status' => 'ok',
                'id' => $temporadaId,
                'ano' => $resolvedYear,
            ];

            // 3. Clubes e participações (clubes_temporadas sem sobrescrever ajuste_pontos/probabilidades)
            $teamsPayload = $this->dataProvider->getTeams($code, $resolvedYear);
            $teams = $teamsPayload['teams'] ?? null;
            if (!is_array($teams)) {
                throw new RuntimeException('Payload de clubes inválido: chave "teams" ausente.');
            }

            $mapaClubesExternalParaId = [];
            $clubesProcessados = 0;

            foreach ($teams as $team) {
                if (!is_array($team) || !isset($team['id'], $team['name'])) {
                    throw new RuntimeException('Registro de clube inválido no payload da API.');
                }

                $externalClubeId = (int) $team['id'];
                $clubeId = $this->clubeRepository->upsert([
                    'external_id' => $externalClubeId,
                    'nome' => (string) $team['name'],
                    'nome_curto' => (string) ($team['shortName'] ?? $team['name']),
                    'sigla' => (string) ($team['tla'] ?? mb_substr((string) $team['name'], 0, 3)),
                    'escudo_url' => $team['crest'] ?? null,
                    'estadio' => $team['venue'] ?? null,
                    'api_updated_at' => $this->normalizeUtcDatetime($team['lastUpdated'] ?? null),
                ]);

                $this->clubeRepository->vincularTemporada($temporadaId, $clubeId);
                $mapaClubesExternalParaId[$externalClubeId] = $clubeId;
                $clubesProcessados++;
                $registrosProcessados++;
            }

            $detalhes['etapas']['clubes'] = [
                'status' => 'ok',
                'total' => $clubesProcessados,
            ];

            // 4. Partidas
            $matchesPayload = $this->dataProvider->getMatches($code, $resolvedYear);
            $matches = $matchesPayload['matches'] ?? null;
            if (!is_array($matches)) {
                throw new RuntimeException('Payload de partidas inválido: chave "matches" ausente.');
            }

            $partidasProcessadas = 0;
            foreach ($matches as $match) {
                if (!is_array($match) || !isset($match['id'], $match['utcDate'], $match['status'], $match['homeTeam']['id'], $match['awayTeam']['id'])) {
                    throw new RuntimeException('Registro de partida inválido no payload da API.');
                }

                $homeExternalId = (int) $match['homeTeam']['id'];
                $awayExternalId = (int) $match['awayTeam']['id'];

                $mandanteId = $mapaClubesExternalParaId[$homeExternalId] ?? $this->resolveClubeIdByExternal($homeExternalId);
                $visitanteId = $mapaClubesExternalParaId[$awayExternalId] ?? $this->resolveClubeIdByExternal($awayExternalId);

                if ($mandanteId === null || $visitanteId === null) {
                    throw new RuntimeException(sprintf(
                        'Clube não encontrado para partida external_id=%d (home=%d, away=%d).',
                        (int) $match['id'],
                        $homeExternalId,
                        $awayExternalId
                    ));
                }

                $this->upsertPartida($temporadaId, $mandanteId, $visitanteId, $match);
                $partidasProcessadas++;
                $registrosProcessados++;
            }

            $detalhes['etapas']['partidas'] = [
                'status' => 'ok',
                'total' => $partidasProcessadas,
            ];

            $duracaoMs = $this->calculateDurationMs($inicioMicro);
            $mensagem = sprintf(
                'Sincronização concluída (%s %d): %d clubes e %d partidas processados.',
                $code,
                $resolvedYear,
                $clubesProcessados,
                $partidasProcessadas
            );

            $this->finalizarSyncLog($logId, 'success', $mensagem, $registrosProcessados, $duracaoMs, $detalhes);
            $this->logInfo($mensagem, $detalhes);

            return [
                'sync_log_id' => $logId,
                'status' => 'success',
                'competicao_id' => $competicaoId,
                'temporada_id' => $temporadaId,
                'season_year' => $resolvedYear,
                'clubes_processados' => $clubesProcessados,
                'partidas_processadas' => $partidasProcessadas,
                'registros_processados' => $registrosProcessados,
                'duracao_ms' => $duracaoMs,
            ];

        } catch (Throwable $exception) {
            $duracaoMs = $this->calculateDurationMs($inicioMicro);

            $mensagemErro = $this->logger->sanitizeString(
                $exception->getMessage()
            );

            $detalhes['erro'] = [
                'tipo' => $exception::class,
                'mensagem' => $mensagemErro,
            ];

            if ($exception instanceof ApiRequestException) {
                $detalhes['erro']['http_status'] = $exception->getHttpStatus();
                $detalhes['erro']['request_counter_reset'] =
                    $exception->getRequestCounterReset();
            }

            try {
                $this->finalizarSyncLog(
                    $logId,
                    'failed',
                    $mensagemErro,
                    $registrosProcessados,
                    $duracaoMs,
                    $detalhes
                );
            } catch (Throwable $logException) {
                $this->logError(
                    'Não foi possível finalizar o registro da sincronização.',
                    [
                        'sync_log_id' => $logId,
                        'erro_log' => $logException->getMessage(),
                    ]
                );
            }

            $this->logError($mensagemErro, $detalhes);

            throw $exception;
        }

    }

    /**
     * @param array<string, mixed> $payload
     */
    private function upsertCompeticao(array $payload): int
    {
        if (!isset($payload['id'], $payload['name'], $payload['code'])) {
            throw new RuntimeException('Payload de competição inválido: campos obrigatórios ausentes.');
        }

        $externalId = (int) $payload['id'];
        $params = [
            ':external_id' => $externalId,
            ':nome' => (string) $payload['name'],
            ':codigo' => strtoupper(trim((string) $payload['code'])),
            ':emblema_url' => $payload['emblem'] ?? null,
            ':api_updated_at' => $this->normalizeUtcDatetime($payload['lastUpdated'] ?? null),
        ];

        $select = $this->pdo->prepare('SELECT id FROM competicoes WHERE external_id = :external_id LIMIT 1');
        $select->execute([':external_id' => $externalId]);
        $existingId = $select->fetchColumn();

        if ($existingId !== false) {
            $update = $this->pdo->prepare(
                'UPDATE competicoes
                    SET nome = :nome,
                        codigo = :codigo,
                        emblema_url = :emblema_url,
                        api_updated_at = :api_updated_at
                  WHERE external_id = :external_id'
            );
            $update->execute($params);
            return (int) $existingId;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO competicoes (external_id, nome, codigo, emblema_url, api_updated_at)
             VALUES (:external_id, :nome, :codigo, :emblema_url, :api_updated_at)'
        );
        $insert->execute($params);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $competitionPayload
     * @return array<string, mixed>
     */
    private function resolveSeasonPayload(array $competitionPayload, ?int $seasonYear): array
    {
        if ($seasonYear === null) {
            if (isset($competitionPayload['currentSeason']) && is_array($competitionPayload['currentSeason'])) {
                $season = $competitionPayload['currentSeason'];
                if (isset($season['id'], $season['startDate'], $season['endDate'])) {
                    return $season;
                }
            }
            throw new RuntimeException('Não foi possível identificar currentSeason no payload da competição.');
        }

        $seasons = $competitionPayload['seasons'] ?? [];
        if (is_array($seasons)) {
            foreach ($seasons as $season) {
                if (is_array($season) && isset($season['startDate']) && str_starts_with((string) $season['startDate'], (string) $seasonYear)) {
                    if (isset($season['id'], $season['startDate'], $season['endDate'])) {
                        return $season;
                    }
                }
            }
        }

        if (isset($competitionPayload['currentSeason']) && is_array($competitionPayload['currentSeason'])) {
            $current = $competitionPayload['currentSeason'];
            if (isset($current['startDate']) && str_starts_with((string) $current['startDate'], (string) $seasonYear)) {
                return $current;
            }
        }

        throw new RuntimeException(sprintf('Temporada %d não encontrada na competição informada.', $seasonYear));
    }

    /**
     * @param array<string, mixed> $seasonData
     */
    private function upsertTemporada(int $competicaoId, array $seasonData, mixed $lastUpdated): int
    {
        $externalId = (int) $seasonData['id'];
        $startDate = (string) $seasonData['startDate'];
        $endDate = (string) $seasonData['endDate'];
        $ano = (int) substr($startDate, 0, 4);
        $rodadaAtual = isset($seasonData['currentMatchday']) && $seasonData['currentMatchday'] !== null
            ? (int) $seasonData['currentMatchday']
            : null;

        $params = [
            ':external_id' => $externalId,
            ':competicao_id' => $competicaoId,
            ':ano' => $ano,
            ':data_inicio' => $startDate,
            ':data_fim' => $endDate,
            ':rodada_atual' => $rodadaAtual,
            ':api_updated_at' => $this->normalizeUtcDatetime($lastUpdated),
        ];

        $select = $this->pdo->prepare('SELECT id FROM temporadas WHERE external_id = :external_id LIMIT 1');
        $select->execute([':external_id' => $externalId]);
        $existingId = $select->fetchColumn();

        if ($existingId !== false) {
            $update = $this->pdo->prepare(
                'UPDATE temporadas
                    SET competicao_id = :competicao_id,
                        ano = :ano,
                        data_inicio = :data_inicio,
                        data_fim = :data_fim,
                        rodada_atual = :rodada_atual,
                        api_updated_at = :api_updated_at
                  WHERE external_id = :external_id'
            );
            $update->execute($params);
            return (int) $existingId;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO temporadas (external_id, competicao_id, ano, data_inicio, data_fim, rodada_atual, api_updated_at)
             VALUES (:external_id, :competicao_id, :ano, :data_inicio, :data_fim, :rodada_atual, :api_updated_at)'
        );
        $insert->execute($params);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $match
     */
    private function upsertPartida(int $temporadaId, int $mandanteId, int $visitanteId, array $match): void
    {
        $score = is_array($match['score'] ?? null) ? $match['score'] : [];
        $fullTime = is_array($score['fullTime'] ?? null) ? $score['fullTime'] : [];
        $halfTime = is_array($score['halfTime'] ?? null) ? $score['halfTime'] : [];

        $golsMandante = array_key_exists('home', $fullTime) && $fullTime['home'] !== null ? (int) $fullTime['home'] : null;
        $golsVisitante = array_key_exists('away', $fullTime) && $fullTime['away'] !== null ? (int) $fullTime['away'] : null;
        $golsMandanteHt = array_key_exists('home', $halfTime) && $halfTime['home'] !== null ? (int) $halfTime['home'] : null;
        $golsVisitanteHt = array_key_exists('away', $halfTime) && $halfTime['away'] !== null ? (int) $halfTime['away'] : null;

        $vencedor = isset($score['winner']) && $score['winner'] !== null
            ? strtoupper(trim((string) $score['winner']))
            : null;

        $rodada = array_key_exists('matchday', $match) && $match['matchday'] !== null
            ? (int) $match['matchday']
            : null;

        $dataHoraUtc = $this->normalizeUtcDatetime($match['utcDate']);
        if ($dataHoraUtc === null) {
            throw new RuntimeException('Data/hora UTC inválida na partida external_id=' . (int) $match['id']);
        }

        $externalId = (int) $match['id'];
        $params = [
            ':external_id' => $externalId,
            ':temporada_id' => $temporadaId,
            ':rodada' => $rodada,
            ':data_hora_utc' => $dataHoraUtc,
            ':status' => strtoupper(trim((string) $match['status'])),
            ':mandante_id' => $mandanteId,
            ':visitante_id' => $visitanteId,
            ':gols_mandante' => $golsMandante,
            ':gols_visitante' => $golsVisitante,
            ':gols_mandante_ht' => $golsMandanteHt,
            ':gols_visitante_ht' => $golsVisitanteHt,
            ':vencedor' => $vencedor,
            ':api_updated_at' => $this->normalizeUtcDatetime($match['lastUpdated'] ?? null),
        ];

        $select = $this->pdo->prepare('SELECT id FROM partidas WHERE external_id = :external_id LIMIT 1');
        $select->execute([':external_id' => $externalId]);
        $existingId = $select->fetchColumn();

        if ($existingId !== false) {
            $update = $this->pdo->prepare(
                'UPDATE partidas
                    SET temporada_id = :temporada_id,
                        rodada = :rodada,
                        data_hora_utc = :data_hora_utc,
                        status = :status,
                        mandante_id = :mandante_id,
                        visitante_id = :visitante_id,
                        gols_mandante = :gols_mandante,
                        gols_visitante = :gols_visitante,
                        gols_mandante_ht = :gols_mandante_ht,
                        gols_visitante_ht = :gols_visitante_ht,
                        vencedor = :vencedor,
                        api_updated_at = :api_updated_at
                  WHERE external_id = :external_id'
            );
            $update->execute($params);
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO partidas (
                external_id,
                temporada_id,
                rodada,
                data_hora_utc,
                status,
                mandante_id,
                visitante_id,
                gols_mandante,
                gols_visitante,
                gols_mandante_ht,
                gols_visitante_ht,
                vencedor,
                api_updated_at
            ) VALUES (
                :external_id,
                :temporada_id,
                :rodada,
                :data_hora_utc,
                :status,
                :mandante_id,
                :visitante_id,
                :gols_mandante,
                :gols_visitante,
                :gols_mandante_ht,
                :gols_visitante_ht,
                :vencedor,
                :api_updated_at
            )'
        );
        $insert->execute($params);
    }

    private function resolveClubeIdByExternal(int $externalId): ?int
    {
        $clube = $this->clubeRepository->findByExternalId($externalId);
        return $clube !== null ? (int) $clube['id'] : null;
    }


    /**
     * @param array<string, mixed> $detalhes
     */
    private function iniciarSyncLog(
        string $tipo,
        DateTimeImmutable $iniciadoEm,
        array $detalhes
    ): int {
        $detalhesSeguros = $this->logger->sanitizeContext($detalhes);

        return $this->syncLogRepository->create([
            'tipo' => $tipo,
            'iniciado_em' => $iniciadoEm,
            'detalhes' => $detalhesSeguros,
        ]);
    }


    /**
     * @param array<string, mixed> $detalhes
     */
    private function finalizarSyncLog(
        int $logId,
        string $status,
        string $mensagem,
        int $registrosProcessados,
        int $duracaoMs,
        array $detalhes
    ): void {
        if ($logId <= 0) {
            return;
        }

        $mensagemSegura = $this->logger->sanitizeString($mensagem);
        $detalhesSeguros = $this->logger->sanitizeContext($detalhes);

        $this->syncLogRepository->finish($logId, [
            'status' => $status,
            'mensagem' => $mensagemSegura,
            'registros_processados' => $registrosProcessados,
            'duracao_ms' => $duracaoMs,
            'detalhes' => $detalhesSeguros,
        ]);
    }

    private function normalizeUtcDatetime(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($raw);
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function calculateDurationMs(float $inicioMicro): int
    {
        return max(0, (int) round((microtime(true) - $inicioMicro) * 1000));
    }

    private function truncateMessage(string $message, int $maxLength): string
    {
        if (mb_strlen($message) <= $maxLength) {
            return $message;
        }

        return mb_substr($message, 0, $maxLength - 3) . '...';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logInfo(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);

        if ($this->legacyLogger !== null) {
            ($this->legacyLogger)(
                'info',
                $this->logger->sanitizeString($message),
                $this->logger->sanitizeContext($context)
            );
        }
    }


    /**
     * @param array<string, mixed> $context
     */
    private function logError(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);

        if ($this->legacyLogger !== null) {
            ($this->legacyLogger)(
                'error',
                $this->logger->sanitizeString($message),
                $this->logger->sanitizeContext($context)
            );
        }
    }

}