<?php

declare(strict_types=1);

namespace App\Repositories;

use InvalidArgumentException;
use PDO;

/**
 * Repositório responsável pela persistência e consulta de usuários no MySQL.
 */
class UsuarioRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Busca um usuário pelo ID interno.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nome, email, senha_hash, clube_favorito_id, criado_em, atualizado_em
               FROM usuarios
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Busca um usuário pelo e-mail único.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nome, email, senha_hash, clube_favorito_id, criado_em, atualizado_em
               FROM usuarios
              WHERE email = :email
              LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Cria um novo usuário e retorna o ID interno gerado.
     *
     * @param array<string, mixed> $usuario
     */
    public function create(array $usuario): int
    {
        if (array_key_exists('senha', $usuario)) {
            throw new InvalidArgumentException('Não é permitido persistir senha em texto puro; informe senha_hash.');
        }

        if (!isset($usuario['nome'], $usuario['email'], $usuario['senha_hash'])) {
            throw new InvalidArgumentException('Os campos nome, email e senha_hash são obrigatórios.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (nome, email, senha_hash, clube_favorito_id)
             VALUES (:nome, :email, :senha_hash, :clube_favorito_id)'
        );

        $stmt->bindValue(':nome', (string) $usuario['nome'], PDO::PARAM_STR);
        $stmt->bindValue(':email', (string) $usuario['email'], PDO::PARAM_STR);
        $stmt->bindValue(':senha_hash', (string) $usuario['senha_hash'], PDO::PARAM_STR);

        $clubeFavoritoId = $usuario['clube_favorito_id'] ?? null;
        if ($clubeFavoritoId === null || $clubeFavoritoId === '') {
            $stmt->bindValue(':clube_favorito_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':clube_favorito_id', (int) $clubeFavoritoId, PDO::PARAM_INT);
        }

        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Atualiza os dados de perfil do usuário (nome, e-mail e/ou clube favorito).
     *
     * @param array<string, mixed> $dados
     */
    public function updateProfile(int $id, array $dados): bool
    {
        if (array_key_exists('senha', $dados) || array_key_exists('senha_hash', $dados)) {
            throw new InvalidArgumentException('Atualização de senha deve utilizar updatePasswordHash().');
        }

        $campos = [];
        $parametros = [':id' => [$id, PDO::PARAM_INT]];

        if (array_key_exists('nome', $dados)) {
            $campos[] = 'nome = :nome';
            $parametros[':nome'] = [(string) $dados['nome'], PDO::PARAM_STR];
        }

        if (array_key_exists('email', $dados)) {
            $campos[] = 'email = :email';
            $parametros[':email'] = [(string) $dados['email'], PDO::PARAM_STR];
        }

        if (array_key_exists('clube_favorito_id', $dados)) {
            $campos[] = 'clube_favorito_id = :clube_favorito_id';
            $clubeId = $dados['clube_favorito_id'];
            if ($clubeId === null || $clubeId === '') {
                $parametros[':clube_favorito_id'] = [null, PDO::PARAM_NULL];
            } else {
                $parametros[':clube_favorito_id'] = [(int) $clubeId, PDO::PARAM_INT];
            }
        }

        if ($campos === []) {
            return false;
        }

        $sql = sprintf(
            'UPDATE usuarios SET %s WHERE id = :id',
            implode(', ', $campos)
        );

        $stmt = $this->pdo->prepare($sql);
        foreach ($parametros as $chave => [$valor, $tipo]) {
            $stmt->bindValue($chave, $valor, $tipo);
        }

        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Atualiza exclusivamente o hash da senha do usuário.
     */
    public function updatePasswordHash(int $id, string $hash): bool
    {
        if ($hash === '') {
            throw new InvalidArgumentException('O hash da senha não pode ser vazio.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE usuarios
                SET senha_hash = :senha_hash
              WHERE id = :id'
        );
        $stmt->bindValue(':senha_hash', $hash, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Remove um usuário pelo ID interno.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM usuarios WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
}