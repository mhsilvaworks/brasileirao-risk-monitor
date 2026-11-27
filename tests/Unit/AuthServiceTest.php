<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\UsuarioRepository;
use App\Services\AuthService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private UsuarioRepository $repository;
    private AuthService $authService;

    // Prepara um banco temporário antes de cada teste.
    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(<<<'SQL'
            CREATE TABLE clubes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                senha_hash TEXT NOT NULL,
                clube_favorito_id INTEGER DEFAULT NULL,
                criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (clube_favorito_id)
                    REFERENCES clubes(id)
                    ON DELETE SET NULL
            )
        SQL);

        $this->repository = new UsuarioRepository($pdo);

        $this->authService = new AuthService(
            $this->repository
        );
    }

    // Cria um usuário para reutilização nos testes.
    private function criarUsuario(): int
    {
        return $this->authService->register(
            'Torcedor Teste',
            'teste@arena.test',
            'Arena1234'
        );
    }

    // Verifica o cadastro e o armazenamento seguro da senha.
    public function testCadastroValido(): void
    {
        $id = $this->criarUsuario();

        $usuario = $this->repository->findById($id);

        self::assertGreaterThan(0, $id);
        self::assertNotNull($usuario);

        self::assertSame(
            'Torcedor Teste',
            $usuario['nome']
        );

        self::assertNotSame(
            'Arena1234',
            $usuario['senha_hash']
        );

        self::assertTrue(
            password_verify(
                'Arena1234',
                $usuario['senha_hash']
            )
        );
    }

    // Impede o cadastro com e-mail inválido.
    public function testCadastroInvalido(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        $this->authService->register(
            'Torcedor Teste',
            'email-invalido',
            'Arena1234'
        );
    }

    // Impede duas contas com o mesmo e-mail.
    public function testRejeitaEmailDuplicado(): void
    {
        $this->criarUsuario();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->criarUsuario();
    }

    // Verifica a autenticação com credenciais corretas.
    public function testLoginValido(): void
    {
        $id = $this->criarUsuario();

        self::assertSame(
            $id,
            $this->authService->authenticate(
                'teste@arena.test',
                'Arena1234'
            )
        );
    }

    // Rejeita senhas incorretas e usuários inexistentes.
    public function testLoginInvalido(): void
    {
        $this->criarUsuario();

        self::assertNull(
            $this->authService->authenticate(
                'teste@arena.test',
                'senha-incorreta'
            )
        );

        self::assertNull(
            $this->authService->authenticate(
                'inexistente@arena.test',
                'Arena1234'
            )
        );
    }

    // Garante que o perfil não exponha o hash da senha.
    public function testPerfilNaoRetornaHash(): void
    {
        $id = $this->criarUsuario();

        $perfil = $this->authService->getProfile($id);

        self::assertNotNull($perfil);

        self::assertArrayNotHasKey(
            'senha_hash',
            $perfil
        );
    }

    // Verifica a atualização dos dados pessoais.
    public function testAtualizacaoDePerfil(): void
    {
        $id = $this->criarUsuario();

        $this->authService->updateProfile(
            $id,
            'Nome Atualizado',
            'novo@arena.test'
        );

        $perfil = $this->authService->getProfile($id);

        self::assertSame(
            'Nome Atualizado',
            $perfil['nome']
        );

        self::assertSame(
            'novo@arena.test',
            $perfil['email']
        );
    }

    // Verifica a substituição da senha antiga pela nova.
    public function testAlteracaoDeSenha(): void
    {
        $id = $this->criarUsuario();

        $this->authService->changePassword(
            $id,
            'Arena1234',
            'NovaSenha1234'
        );

        self::assertNull(
            $this->authService->authenticate(
                'teste@arena.test',
                'Arena1234'
            )
        );

        self::assertSame(
            $id,
            $this->authService->authenticate(
                'teste@arena.test',
                'NovaSenha1234'
            )
        );
    }

    // Impede alterações com a senha atual incorreta.
    public function testRejeitaAlteracaoComSenhaIncorreta(): void
    {
        $id = $this->criarUsuario();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->authService->changePassword(
            $id,
            'senha-incorreta',
            'NovaSenha1234'
        );
    }

    // Verifica a exclusão definitiva do usuário.
    public function testExclusaoDeConta(): void
    {
        $id = $this->criarUsuario();

        $this->authService->deleteAccount(
            $id,
            'Arena1234'
        );

        self::assertNull(
            $this->authService->getProfile($id)
        );

        self::assertNull(
            $this->authService->authenticate(
                'teste@arena.test',
                'Arena1234'
            )
        );
    }

    // Impede a exclusão sem a senha correta.
    public function testRejeitaExclusaoComSenhaIncorreta(): void
    {
        $id = $this->criarUsuario();

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->authService->deleteAccount(
            $id,
            'senha-incorreta'
        );
    }
}
