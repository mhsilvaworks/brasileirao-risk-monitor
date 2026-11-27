<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UsuarioRepository;
use App\Repositories\ClubeRepository;
use InvalidArgumentException;
use PDOException;

final class AuthService
{
    public function __construct(
        private UsuarioRepository $usuarioRepository,
        private ?ClubeRepository $clubeRepository = null
    ) {
    }

    public function authenticate(
        string $email,
        string $senha
    ): ?int {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') {
            return null;
        }

        $usuario = $this->usuarioRepository->findByEmail($email);

        if ($usuario === null) {
            return null;
        }

        if (!password_verify($senha, $usuario['senha_hash'])) {
            return null;
        }

        return (int) $usuario['id'];
    }

    public function register(
        string $nome,
        string $email,
        string $senha,
        ?int $clubeFavoritoId = null
    ): int {
        $nome = trim($nome);
        $email = strtolower(trim($email));

        if ($nome === '' || strlen($nome) > 120) {
            throw new InvalidArgumentException(
                'Informe um nome válido de até 120 bytes.'
            );
        }

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 190
        ) {
            throw new InvalidArgumentException(
                'Informe um e-mail válido.'
            );
        }

        if (
            strlen($senha) < 8
            || strlen($senha) > 72
            || str_contains($senha, "\0")
        ) {
            throw new InvalidArgumentException(
                'A senha deve possuir entre 8 e 72 bytes válidos.'
            );
        }

        if ($this->usuarioRepository->findByEmail($email) !== null) {
            throw new InvalidArgumentException(
                'Este e-mail já está cadastrado.'
            );
        }

        if ($clubeFavoritoId !== null) {
            if (
                $clubeFavoritoId <= 0
                || $this->clubeRepository === null
                || $this->clubeRepository->findById($clubeFavoritoId) === null
            ) {
                throw new InvalidArgumentException(
                    'Clube favorito inválido.'
                );
            }
        }

        try {
            return $this->usuarioRepository->create([
                'nome' => $nome,
                'email' => $email,
                'senha_hash' => password_hash(
                    $senha,
                    PASSWORD_DEFAULT
                ),
                'clube_favorito_id' => $clubeFavoritoId,
            ]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new InvalidArgumentException(
                    'Este e-mail já está cadastrado.'
                );
            }

            throw $e;
        }
    }
        public function getProfile(int $id): ?array
    {
        $usuario = $this->usuarioRepository->findById($id);

        if ($usuario === null) {
            return null;
        }

        unset($usuario['senha_hash']);

        return $usuario;
    }

    public function updateProfile(
        int $id,
        string $nome,
        string $email,
        ?int $clubeFavoritoId = null
    ): void {
        $nome = trim($nome);
        $email = strtolower(trim($email));

        if ($nome === '' || strlen($nome) > 120) {
            throw new InvalidArgumentException(
                'Informe um nome válido de até 120 bytes.'
            );
        }

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 190
        ) {
            throw new InvalidArgumentException(
                'Informe um e-mail válido.'
            );
        }

        $existente = $this->usuarioRepository->findByEmail($email);

        if (
            $existente !== null
            && (int) $existente['id'] !== $id
        ) {
            throw new InvalidArgumentException(
                'Este e-mail já está cadastrado.'
            );
        }

        if ($clubeFavoritoId !== null) {
            if (
                $clubeFavoritoId <= 0
                || $this->clubeRepository === null
                || $this->clubeRepository->findById($clubeFavoritoId) === null
            ) {
                throw new InvalidArgumentException(
                    'Clube favorito inválido.'
                );
            }
        }

        try {
            $this->usuarioRepository->updateProfile($id, [
                'nome' => $nome,
                'email' => $email,
                'clube_favorito_id' => $clubeFavoritoId,
            ]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new InvalidArgumentException(
                    'Este e-mail já está cadastrado.'
                );
            }

            throw $e;
        }
    }

    public function changePassword(
        int $id,
        string $senhaAtual,
        string $novaSenha
    ): void {
        $usuario = $this->usuarioRepository->findById($id);

        if (
            $usuario === null
            || !password_verify($senhaAtual, $usuario['senha_hash'])
        ) {
            throw new InvalidArgumentException(
                'Senha atual incorreta.'
            );
        }

        if (
            strlen($novaSenha) < 8
            || strlen($novaSenha) > 72
            || str_contains($novaSenha, "\0")
        ) {
            throw new InvalidArgumentException(
                'A senha deve possuir entre 8 e 72 bytes válidos.'
            );
        }

        if (!$this->usuarioRepository->updatePasswordHash(
            $id,
            password_hash($novaSenha, PASSWORD_DEFAULT)
        )) {
            throw new \RuntimeException(
                'Não foi possível atualizar a senha.'
            );
        }
    }

    public function deleteAccount(
        int $id,
        string $senhaAtual
    ): void {
        $usuario = $this->usuarioRepository->findById($id);

        if (
            $usuario === null
            || !password_verify($senhaAtual, $usuario['senha_hash'])
        ) {
            throw new InvalidArgumentException(
                'Senha atual incorreta.'
            );
        }

        if (!$this->usuarioRepository->delete($id)) {
            throw new \RuntimeException(
                'Não foi possível excluir a conta.'
            );
        }
    }
}