<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\SyncLogRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

class SyncLogRepositoryTest extends TestCase
{
    private function createSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
            )
        ');

        return $pdo;
    }

    public function testCriaRegistroComStatusRunning(): void
    {
        $pdo = $this->createSqliteSchema();
        $repository = new SyncLogRepository($pdo);

        $id = $repository->create([
            'tipo' => 'sincronizacao',
            'iniciado_em' => new DateTimeImmutable(
                '2026-11-25 20:00:00',
                new DateTimeZone('America/Sao_Paulo')
            ),
            'detalhes' => [
                'competition_code' => 'BSA',
                'season_year' => 2026,
            ],
        ]);

        $this->assertGreaterThan(0, $id);

        $stmt = $pdo->prepare(
            'SELECT * FROM sync_logs WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);

        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('sincronizacao', $log['tipo']);
        $this->assertSame('running', $log['status']);
        $this->assertSame(0, (int) $log['registros_processados']);
        $this->assertNull($log['finalizado_em']);
        $this->assertNull($log['duracao_ms']);
        $this->assertStringContainsString('BSA', $log['detalhes_json']);
    }

    public function testFinalizaRegistroComSucesso(): void
    {
        $pdo = $this->createSqliteSchema();
        $repository = new SyncLogRepository($pdo);

        $id = $repository->create([
            'tipo' => 'sincronizacao',
            'iniciado_em' => new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC')
            ),
        ]);

        $repository->finish($id, [
            'status' => 'success',
            'mensagem' => 'Sincronização concluída.',
            'registros_processados' => 25,
            'duracao_ms' => 1200,
            'detalhes' => ['competition_code' => 'BSA'],
        ]);

        $stmt = $pdo->prepare(
            'SELECT * FROM sync_logs WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);

        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('success', $log['status']);
        $this->assertSame('Sincronização concluída.', $log['mensagem']);
        $this->assertSame(25, (int) $log['registros_processados']);
        $this->assertSame(1200, (int) $log['duracao_ms']);
        $this->assertNotNull($log['finalizado_em']);
    }

    public function testFinalizaRegistroComFalha(): void
    {
        $pdo = $this->createSqliteSchema();
        $repository = new SyncLogRepository($pdo);

        $id = $repository->create([
            'tipo' => 'sincronizacao',
            'iniciado_em' => new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC')
            ),
        ]);

        $repository->finish($id, [
            'status' => 'failed',
            'mensagem' => 'Rate limit excedido.',
            'registros_processados' => 0,
            'duracao_ms' => 500,
            'detalhes' => ['http_status' => 429],
        ]);

        $stmt = $pdo->prepare(
            'SELECT status, mensagem, detalhes_json
               FROM sync_logs WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);

        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('failed', $log['status']);
        $this->assertSame('Rate limit excedido.', $log['mensagem']);
        $this->assertStringContainsString('429', $log['detalhes_json']);
    }

    public function testRejeitaStatusFinalInvalido(): void
    {
        $pdo = $this->createSqliteSchema();
        $repository = new SyncLogRepository($pdo);

        $this->expectException(InvalidArgumentException::class);

        $repository->finish(1, [
            'status' => 'desconhecido',
        ]);
    }

    public function testRejeitaRegistroInexistente(): void
    {
        $pdo = $this->createSqliteSchema();
        $repository = new SyncLogRepository($pdo);

        $this->expectException(InvalidArgumentException::class);

        $repository->finish(999, [
            'status' => 'success',
            'mensagem' => 'Concluído.',
        ]);
    }

    public function testAtualizaMesmoRegistroSemCriarOutro(): void
    {
        $pdo = $this->createSqliteSchema();
        $repository = new SyncLogRepository($pdo);

        $id = $repository->create([
            'tipo' => 'sincronizacao',
            'iniciado_em' => new DateTimeImmutable(
                'now',
                new DateTimeZone('UTC')
            ),
        ]);

        $repository->finish($id, [
            'status' => 'success',
            'mensagem' => 'Concluído.',
        ]);

        $total = (int) $pdo->query(
            'SELECT COUNT(*) FROM sync_logs'
        )->fetchColumn();

        $this->assertSame(1, $total);
    }
}
