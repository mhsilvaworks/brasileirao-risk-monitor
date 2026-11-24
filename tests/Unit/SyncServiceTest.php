<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\DataProviderInterface;
use App\Infrastructure\Fixtures\FixtureDataProvider;
use App\Infrastructure\FootballData\ApiRequestException;
use App\Repositories\ClubeRepository;
use App\Services\SyncService;
use PDO;
use PHPUnit\Framework\TestCase;

class SyncServiceTest extends TestCase
{
    private function createSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('
            CREATE TABLE competicoes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                external_id INTEGER NOT NULL UNIQUE,
                nome TEXT NOT NULL,
                codigo TEXT NOT NULL UNIQUE,
                emblema_url TEXT NULL,
                api_updated_at TEXT NULL,
                criado_em TEXT DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ');

        $pdo->exec('
            CREATE TABLE temporadas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                external_id INTEGER NOT NULL UNIQUE,
                competicao_id INTEGER NOT NULL,
                ano INTEGER NOT NULL,
                data_inicio TEXT NOT NULL,
                data_fim TEXT NOT NULL,
                rodada_atual INTEGER NULL,
                api_updated_at TEXT NULL,
                criado_em TEXT DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ');

        $pdo->exec('
            CREATE TABLE clubes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                external_id INTEGER NOT NULL UNIQUE,
                nome TEXT NOT NULL,
                nome_curto TEXT NOT NULL,
                sigla TEXT NOT NULL,
                escudo_url TEXT NULL,
                estadio TEXT NULL,
                api_updated_at TEXT NULL,
                criado_em TEXT DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ');

        $pdo->exec('
            CREATE TABLE clubes_temporadas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                temporada_id INTEGER NOT NULL,
                clube_id INTEGER NOT NULL,
                ajuste_pontos INTEGER NOT NULL DEFAULT 0,
                motivo_ajuste TEXT NULL,
                probabilidade_campeao REAL NULL,
                probabilidade_libertadores REAL NULL,
                probabilidade_sulamericana REAL NULL,
                probabilidade_rebaixamento REAL NULL,
                probabilidades_posicoes TEXT NULL,
                probabilidades_calculadas_em TEXT NULL,
                UNIQUE(temporada_id, clube_id)
            );
        ');

        $pdo->exec('
            CREATE TABLE partidas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                external_id INTEGER NOT NULL UNIQUE,
                temporada_id INTEGER NOT NULL,
                rodada INTEGER NULL,
                data_hora_utc TEXT NOT NULL,
                status TEXT NOT NULL,
                mandante_id INTEGER NOT NULL,
                visitante_id INTEGER NOT NULL,
                gols_mandante INTEGER NULL,
                gols_visitante INTEGER NULL,
                gols_mandante_ht INTEGER NULL,
                gols_visitante_ht INTEGER NULL,
                vencedor TEXT NULL,
                api_updated_at TEXT NULL
            );
        ');

        $pdo->exec('
            CREATE TABLE sync_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tipo TEXT NOT NULL,
                status TEXT NOT NULL,
                mensagem TEXT NULL,
                registros_processados INTEGER NOT NULL DEFAULT 0,
                iniciado_em TEXT NOT NULL,
                finalizado_em TEXT NULL,
                duracao_ms INTEGER NULL,
                detalhes_json TEXT NULL,
                criado_em TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ');

        return $pdo;
    }

    private function createSqliteClubeRepository(PDO $pdo): ClubeRepository
    {
        return new class($pdo) extends ClubeRepository {
            private PDO $sqlitePdo;

            public function __construct(PDO $pdo)
            {
                parent::__construct($pdo);
                $this->sqlitePdo = $pdo;
            }

            public function upsert(array $dados): int
            {
                $externalId = (int) $dados['external_id'];
                $existing = $this->findByExternalId($externalId);

                $params = [
                    ':external_id' => $externalId,
                    ':nome' => (string) $dados['nome'],
                    ':nome_curto' => (string) ($dados['nome_curto'] ?? $dados['nome']),
                    ':sigla' => (string) $dados['sigla'],
                    ':escudo_url' => $dados['escudo_url'] ?? null,
                    ':estadio' => $dados['estadio'] ?? null,
                    ':api_updated_at' => $dados['api_updated_at'] ?? null,
                ];

                if ($existing !== null) {
                    $stmt = $this->sqlitePdo->prepare(
                        'UPDATE clubes
                            SET nome = :nome,
                                nome_curto = :nome_curto,
                                sigla = :sigla,
                                escudo_url = :escudo_url,
                                estadio = :estadio,
                                api_updated_at = :api_updated_at
                          WHERE external_id = :external_id'
                    );
                    $stmt->execute($params);
                    return (int) $existing['id'];
                }

                $stmt = $this->sqlitePdo->prepare(
                    'INSERT INTO clubes (external_id, nome, nome_curto, sigla, escudo_url, estadio, api_updated_at)
                     VALUES (:external_id, :nome, :nome_curto, :sigla, :escudo_url, :estadio, :api_updated_at)'
                );
                $stmt->execute($params);

                return (int) $this->sqlitePdo->lastInsertId();
            }

            public function vincularTemporada(int $temporadaId, int $clubeId): void
            {
                $stmt = $this->sqlitePdo->prepare(
                    'INSERT OR IGNORE INTO clubes_temporadas (temporada_id, clube_id, ajuste_pontos)
                     VALUES (:temporada_id, :clube_id, 0)'
                );
                $stmt->execute([
                    ':temporada_id' => $temporadaId,
                    ':clube_id' => $clubeId,
                ]);
            }
        };
    }

    public function testSincronizaFixturesComIdempotenciaEPreservaAjustesExistentes(): void
    {
        $pdo = $this->createSqliteSchema();
        $repo = $this->createSqliteClubeRepository($pdo);
        $provider = new FixtureDataProvider(__DIR__ . '/../../docs/api-recognition');
        $service = new SyncService($pdo, $provider, $repo);

        $resultado1 = $service->syncSeason('BSA', 2026);
        $this->assertSame('success', $resultado1['status']);
        $this->assertSame(20, $resultado1['clubes_processados']);
        $this->assertGreaterThan(0, $resultado1['partidas_processadas']);

        $pdo->exec('UPDATE clubes_temporadas SET ajuste_pontos = -3, probabilidade_campeao = 12.50 WHERE id = 1');

        $resultado2 = $service->syncSeason('BSA', 2026);
        $this->assertSame('success', $resultado2['status']);

        $totalClubes = (int) $pdo->query('SELECT COUNT(*) FROM clubes')->fetchColumn();
        $totalVinculos = (int) $pdo->query('SELECT COUNT(*) FROM clubes_temporadas')->fetchColumn();
        $totalPartidas = (int) $pdo->query('SELECT COUNT(*) FROM partidas')->fetchColumn();
        $totalLogs = (int) $pdo->query("SELECT COUNT(*) FROM sync_logs WHERE status = 'success'")->fetchColumn();

        $this->assertSame(20, $totalClubes);
        $this->assertSame(20, $totalVinculos);
        $this->assertSame($resultado1['partidas_processadas'], $totalPartidas);
        $this->assertSame(2, $totalLogs);

        $rowPreservada = $pdo->query('SELECT ajuste_pontos, probabilidade_campeao FROM clubes_temporadas WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(-3, (int) $rowPreservada['ajuste_pontos']);
        $this->assertEquals(12.50, (float) $rowPreservada['probabilidade_campeao']);
    }

    public function testRegistraFalhaEmSyncLogsEPreservaDadosAnterioresQuandoApiFalhaComHttp429(): void
    {
        $pdo = $this->createSqliteSchema();
        $repo = $this->createSqliteClubeRepository($pdo);
        $fixtureProvider = new FixtureDataProvider(__DIR__ . '/../../docs/api-recognition');
        $serviceInicial = new SyncService($pdo, $fixtureProvider, $repo);
        $serviceInicial->syncSeason('BSA', 2026);

        $partidasAntes = (int) $pdo->query('SELECT COUNT(*) FROM partidas')->fetchColumn();

        $failingProvider = new class implements DataProviderInterface {
            public function getCompetition(string $competitionCode = 'BSA'): array
            {
                throw new ApiRequestException('Rate limit excedido.', 429, '45');
            }
            public function getTeams(string $competitionCode, int $seasonYear): array { return []; }
            public function getMatches(string $competitionCode, int $seasonYear): array { return []; }
            public function getStandings(string $competitionCode, int $seasonYear): array { return []; }
        };

        $serviceFalha = new SyncService($pdo, $failingProvider, $repo);

        try {
            $serviceFalha->syncSeason('BSA', 2026);
            $this->fail('Era esperada ApiRequestException.');
        } catch (ApiRequestException $e) {
            $this->assertSame(429, $e->getHttpStatus());
        }

        $partidasDepois = (int) $pdo->query('SELECT COUNT(*) FROM partidas')->fetchColumn();
        $this->assertSame($partidasAntes, $partidasDepois);

        $ultimoLog = $pdo->query('SELECT status, mensagem, finalizado_em, detalhes_json FROM sync_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('failed', $ultimoLog['status']);
        $this->assertStringContainsString('Rate limit excedido.', $ultimoLog['mensagem']);
        $this->assertNotNull($ultimoLog['finalizado_em']);
        $this->assertStringContainsString('429', (string) $ultimoLog['detalhes_json']);
    }
}