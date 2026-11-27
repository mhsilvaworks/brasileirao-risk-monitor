<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\TemporadaRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class TemporadaRepositoryTest extends TestCase
{
    public function testEncontraTemporadaAtivaDaCompeticao(): void
    {
        $pdo = $this->createDatabase();
        $dataAtual = (new DateTimeImmutable(
            'now',
            new DateTimeZone('America/Sao_Paulo')
        ))->format('Y-m-d');

        $pdo->exec("INSERT INTO competicoes (id, codigo) VALUES (1, 'BSA'), (2, 'OUTRA')");
        $stmt = $pdo->prepare(
            'INSERT INTO temporadas (id, competicao_id, ano, data_inicio, data_fim)
             VALUES (:id, :competicao_id, :ano, :data_inicio, :data_fim)'
        );
        $stmt->execute([
            ':id' => 10,
            ':competicao_id' => 1,
            ':ano' => 2026,
            ':data_inicio' => $dataAtual,
            ':data_fim' => $dataAtual,
        ]);
        $stmt->execute([
            ':id' => 11,
            ':competicao_id' => 2,
            ':ano' => 2026,
            ':data_inicio' => $dataAtual,
            ':data_fim' => $dataAtual,
        ]);

        $temporada = (new TemporadaRepository($pdo))
            ->findActiveByCompetitionCode('BSA');

        self::assertNotNull($temporada);
        self::assertSame(10, (int) $temporada['id']);
        self::assertSame(2026, (int) $temporada['ano']);
    }

    public function testRetornaNuloQuandoNaoHaTemporadaAtiva(): void
    {
        $pdo = $this->createDatabase();
        $pdo->exec("INSERT INTO competicoes (id, codigo) VALUES (1, 'BSA')");

        $temporada = (new TemporadaRepository($pdo))
            ->findActiveByCompetitionCode('BSA');

        self::assertNull($temporada);
    }

    private function createDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE competicoes (id INTEGER PRIMARY KEY, codigo TEXT NOT NULL)');
        $pdo->exec(
            'CREATE TABLE temporadas (
                id INTEGER PRIMARY KEY,
                competicao_id INTEGER NOT NULL,
                ano INTEGER NOT NULL,
                data_inicio TEXT NOT NULL,
                data_fim TEXT NOT NULL,
                rodada_atual INTEGER NULL
            )'
        );

        return $pdo;
    }
}
