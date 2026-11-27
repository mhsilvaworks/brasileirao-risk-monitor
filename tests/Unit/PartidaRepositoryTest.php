<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\PartidaRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class PartidaRepositoryTest extends TestCase
{
    private function createSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE competicoes (id INTEGER PRIMARY KEY, codigo TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE temporadas (
            id INTEGER PRIMARY KEY,
            competicao_id INTEGER NOT NULL,
            data_inicio TEXT NOT NULL,
            data_fim TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE clubes (id INTEGER PRIMARY KEY, nome TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE partidas (
            id INTEGER PRIMARY KEY,
            temporada_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            data_hora_utc TEXT NOT NULL,
            mandante_id INTEGER NOT NULL,
            visitante_id INTEGER NOT NULL,
            gols_mandante INTEGER NULL,
            gols_visitante INTEGER NULL,
            api_updated_at TEXT NULL
        )');

        return $pdo;
    }

    public function testRetornaSomentePartidasAoVivoDaTemporadaVigente(): void
    {
        $pdo = $this->createSqliteSchema();
        $hoje = new DateTimeImmutable('today', new DateTimeZone('America/Sao_Paulo'));
        $inicio = $hoje->modify('-10 days')->format('Y-m-d');
        $fim = $hoje->modify('+10 days')->format('Y-m-d');
        $temporadaAnteriorInicio = $hoje->modify('-50 days')->format('Y-m-d');
        $temporadaAnteriorFim = $hoje->modify('-40 days')->format('Y-m-d');

        $pdo->exec("INSERT INTO competicoes (id, codigo) VALUES (1, 'BSA'), (2, 'OUTRA')");
        $stmtTemporada = $pdo->prepare(
            'INSERT INTO temporadas (id, competicao_id, data_inicio, data_fim)
             VALUES (:id, :competicao_id, :data_inicio, :data_fim)'
        );
        $stmtTemporada->execute([
            ':id' => 1,
            ':competicao_id' => 1,
            ':data_inicio' => $inicio,
            ':data_fim' => $fim,
        ]);
        $stmtTemporada->execute([
            ':id' => 2,
            ':competicao_id' => 1,
            ':data_inicio' => $temporadaAnteriorInicio,
            ':data_fim' => $temporadaAnteriorFim,
        ]);
        $stmtTemporada->execute([
            ':id' => 3,
            ':competicao_id' => 2,
            ':data_inicio' => $inicio,
            ':data_fim' => $fim,
        ]);

        $pdo->exec("INSERT INTO clubes (id, nome) VALUES
            (1, 'Clube favorito'), (2, 'Mandante'), (3, 'Visitante'), (4, 'Outro clube')");
        $pdo->exec("INSERT INTO partidas
            (id, temporada_id, status, data_hora_utc, mandante_id, visitante_id,
             gols_mandante, gols_visitante, api_updated_at) VALUES
            (1, 1, 'IN_PLAY', '2026-11-27 12:00:00', 1, 2, 2, NULL, NULL),
            (2, 1, 'PAUSED', '2026-11-27 13:00:00', 3, 1, 0, 1, '2026-11-27 13:05:00'),
            (3, 1, 'SCHEDULED', '2026-11-27 14:00:00', 1, 3, NULL, NULL, NULL),
            (4, 1, 'IN_PLAY', '2026-11-27 15:00:00', 2, 4, 0, 0, NULL),
            (5, 2, 'IN_PLAY', '2026-01-01 15:00:00', 1, 3, 0, 0, NULL),
            (6, 3, 'IN_PLAY', '2026-11-27 16:00:00', 1, 4, 0, 0, NULL)");

        $repository = new PartidaRepository($pdo);
        $partidas = $repository->findLiveByClub(1);

        $this->assertCount(2, $partidas);
        $this->assertSame(['IN_PLAY', 'PAUSED'], array_column($partidas, 'status'));
        $this->assertSame('Clube favorito', $partidas[0]['mandante_nome']);
        $this->assertNull($partidas[0]['gols_visitante']);
        $this->assertNull($partidas[0]['api_updated_at']);
        $this->assertSame('2026-11-27 13:05:00', $partidas[1]['api_updated_at']);
    }

    public function testRetornaListaVaziaQuandoClubeNaoTemPartidaAoVivo(): void
    {
        $pdo = $this->createSqliteSchema();
        $repository = new PartidaRepository($pdo);

        $this->assertSame([], $repository->findLiveByClub(999));
    }
}
