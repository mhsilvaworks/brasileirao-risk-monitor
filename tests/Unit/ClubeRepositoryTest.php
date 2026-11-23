<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\ClubeRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class ClubeRepositoryTest extends TestCase
{
    public function testUpsertEnviaNullParaCamposOpcionaisAusentes(): void
    {
        // Simula a execução do INSERT/UPDATE.
        $statementInsert = $this->createMock(PDOStatement::class);

        $statementInsert->expects($this->once())
            ->method('execute')
            ->with([
                ':external_id' => 123,
                ':nome' => 'Clube Teste',
                ':nome_curto' => 'Clube Teste',
                ':sigla' => 'CTE',
                ':escudo_url' => null,
                ':estadio' => null,
                ':api_updated_at' => null,
            ]);

        // Simula a consulta que procura o clube após o upsert.
        $statementSelect = $this->createMock(PDOStatement::class);

        $statementSelect->expects($this->once())
            ->method('execute')
            ->with([':external_id' => 123]);

        $statementSelect->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['id' => 55]);

        // Simula a conexão com o banco.
        $pdo = $this->createMock(PDO::class);

        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls(
                $statementInsert,
                $statementSelect
            );

        $repository = new ClubeRepository($pdo);

        $id = $repository->upsert([
            'external_id' => 123,
            'nome' => 'Clube Teste',
            'sigla' => 'CTE',
        ]);

        $this->assertSame(55, $id);
    }

    public function testUpsertUsaAtualizacaoQuandoExternalIdJaExiste(): void
    {
        $statementInsert = $this->createMock(PDOStatement::class);

        $statementInsert->expects($this->once())
            ->method('execute')
            ->with([
                ':external_id' => 123,
                ':nome' => 'Clube Atualizado',
                ':nome_curto' => 'Clube Atualizado',
                ':sigla' => 'CAT',
                ':escudo_url' => null,
                ':estadio' => null,
                ':api_updated_at' => null,
            ]);

        $statementSelect = $this->createMock(PDOStatement::class);

        $statementSelect->method('execute')
            ->with([':external_id' => 123]);

        $statementSelect->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['id' => 55]);

        $pdo = $this->createMock(PDO::class);

        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(
                function (string $sql) use ($statementInsert, $statementSelect) {
                    if (str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
                        return $statementInsert;
                    }

                    return $statementSelect;
                }
            );

        $repository = new ClubeRepository($pdo);

        $id = $repository->upsert([
            'external_id' => 123,
            'nome' => 'Clube Atualizado',
            'sigla' => 'CAT',
        ]);

        $this->assertSame(55, $id);
    }

    public function testVincularTemporadaUsaInsertIgnore(): void
    {
        $statement = $this->createMock(PDOStatement::class);

        $statement->expects($this->once())
            ->method('execute')
            ->with([
                ':temporada_id' => 2026,
                ':clube_id' => 55,
            ]);

        $pdo = $this->createMock(PDO::class);

        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT IGNORE'))
            ->willReturn($statement);

        $repository = new ClubeRepository($pdo);

        $repository->vincularTemporada(2026, 55);
    }
}
