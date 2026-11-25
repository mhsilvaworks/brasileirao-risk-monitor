<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\UsuarioRepository;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class UsuarioRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UsuarioRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE clubes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL
            )
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                senha_hash TEXT NOT NULL,
                clube_favorito_id INTEGER DEFAULT NULL,
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_usuarios_clube
                    FOREIGN KEY (clube_favorito_id) REFERENCES clubes(id)
                    ON DELETE SET NULL
            )
        SQL);

        $this->repository = new UsuarioRepository($this->pdo);
    }

    public function testCreateAndFindByIdAndEmailWithNullableClubeFavorito(): void
    {
        $id = $this->repository->create([
            'nome' => 'Rodrigo Paiva',
            'email' => 'rodrigo@arena.test',
            'senha_hash' => '$2y$10$hashDeTesteSeguro1234567890',
        ]);

        $this->assertGreaterThan(0, $id);

        $porId = $this->repository->findById($id);
        $this->assertNotNull($porId);
        $this->assertSame('Rodrigo Paiva', $porId['nome']);
        $this->assertSame('rodrigo@arena.test', $porId['email']);
        $this->assertSame('$2y$10$hashDeTesteSeguro1234567890', $porId['senha_hash']);
        $this->assertNull($porId['clube_favorito_id']);

        $porEmail = $this->repository->findByEmail('rodrigo@arena.test');
        $this->assertNotNull($porEmail);
        $this->assertSame($id, (int) $porEmail['id']);
    }

    public function testFindReturnsNullWhenUserDoesNotExist(): void
    {
        $this->assertNull($this->repository->findById(9999));
        $this->assertNull($this->repository->findByEmail('inexistente@arena.test'));
    }

    public function testCreateRejectsPlainTextPasswordKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->create([
            'nome' => 'Usuario Invalido',
            'email' => 'invalido@arena.test',
            'senha' => '123456',
        ]);
    }

    public function testCreateEnforcesUniqueEmailConstraint(): void
    {
        $this->repository->create([
            'nome' => 'Primeiro Usuario',
            'email' => 'duplicado@arena.test',
            'senha_hash' => '$2y$10$hash1',
        ]);

        $this->expectException(PDOException::class);

        $this->repository->create([
            'nome' => 'Segundo Usuario',
            'email' => 'duplicado@arena.test',
            'senha_hash' => '$2y$10$hash2',
        ]);
    }

    public function testUpdateProfileUpdatesFieldsAndSetsClubeFavoritoNullable(): void
    {
        $this->pdo->exec("INSERT INTO clubes (id, nome) VALUES (10, 'Botafogo')");

        $id = $this->repository->create([
            'nome' => 'Nome Antigo',
            'email' => 'antigo@arena.test',
            'senha_hash' => '$2y$10$hashInicial',
            'clube_favorito_id' => 10,
        ]);

        $atualizado = $this->repository->updateProfile($id, [
            'nome' => 'Nome Atualizado',
            'email' => 'novo@arena.test',
            'clube_favorito_id' => null,
        ]);

        $this->assertTrue($atualizado);

        $usuario = $this->repository->findById($id);
        $this->assertNotNull($usuario);
        $this->assertSame('Nome Atualizado', $usuario['nome']);
        $this->assertSame('novo@arena.test', $usuario['email']);
        $this->assertNull($usuario['clube_favorito_id']);
    }

    public function testUpdateProfileReturnsFalseWhenNoFieldsProvidedOrUserMissing(): void
    {
        $this->assertFalse($this->repository->updateProfile(1, []));
        $this->assertFalse($this->repository->updateProfile(9999, ['nome' => 'Ninguem']));
    }

    public function testUpdatePasswordHashUpdatesOnlyHash(): void
    {
        $id = $this->repository->create([
            'nome' => 'Usuario Senha',
            'email' => 'senha@arena.test',
            'senha_hash' => '$2y$10$hashAntigo',
        ]);

        $this->assertTrue($this->repository->updatePasswordHash($id, '$2y$10$hashNovo'));

        $usuario = $this->repository->findById($id);
        $this->assertNotNull($usuario);
        $this->assertSame('$2y$10$hashNovo', $usuario['senha_hash']);
        $this->assertFalse($this->repository->updatePasswordHash(9999, '$2y$10$outroHash'));
    }

    public function testDeleteRemovesExistingUserAndReturnsFalseWhenMissing(): void
    {
        $id = $this->repository->create([
            'nome' => 'Usuario Remover',
            'email' => 'remover@arena.test',
            'senha_hash' => '$2y$10$hashRemover',
        ]);

        $this->assertTrue($this->repository->delete($id));
        $this->assertNull($this->repository->findById($id));
        $this->assertFalse($this->repository->delete($id));
    }
}