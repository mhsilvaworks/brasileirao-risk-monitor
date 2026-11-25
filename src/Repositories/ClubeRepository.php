<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Repositório responsável pela persistência e consulta de clubes
 * e seus vínculos por temporada no MySQL.
 */
class ClubeRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Busca um clube pelo ID interno.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, external_id, nome, nome_curto, sigla, escudo_url, estadio, api_updated_at
               FROM clubes
              WHERE id = :id
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Busca um clube pelo external_id da football-data.org.
     *
     * @return array<string, mixed>|null
     */
    public function findByExternalId(int $externalId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, external_id, nome, nome_curto, sigla, escudo_url, estadio, api_updated_at
               FROM clubes
              WHERE external_id = :external_id
              LIMIT 1'
        );
        $stmt->execute([':external_id' => $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Lista todos os clubes cadastrados ordenados por nome curto.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, external_id, nome, nome_curto, sigla, escudo_url, estadio, api_updated_at
               FROM clubes
              ORDER BY nome_curto ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista os clubes participantes de uma temporada com seus ajustes administrativos.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByTemporada(int $temporadaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id,
                    c.external_id,
                    c.nome,
                    c.nome_curto,
                    c.sigla,
                    c.escudo_url,
                    c.estadio,
                    ct.ajuste_pontos,
                    ct.motivo_ajuste,
                    ct.probabilidade_campeao,
                    ct.probabilidade_libertadores,
                    ct.probabilidade_sulamericana,
                    ct.probabilidade_rebaixamento,
                    ct.probabilidades_posicoes,
                    ct.probabilidades_calculadas_em
               FROM clubes c
               INNER JOIN clubes_temporadas ct ON ct.clube_id = c.id
              WHERE ct.temporada_id = :temporada_id
              ORDER BY c.nome_curto ASC'
        );
        $stmt->execute([':temporada_id' => $temporadaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Insere ou atualiza um clube de forma idempotente pelo external_id
     * e retorna o ID interno na tabela clubes.
     *
     * @param array<string, mixed> $dados
     */
    public function upsert(array $dados): int
    {
        $sql = 'INSERT INTO clubes (
                    external_id,
                    nome,
                    nome_curto,
                    sigla,
                    escudo_url,
                    estadio,
                    api_updated_at
                ) VALUES (
                    :external_id,
                    :nome,
                    :nome_curto,
                    :sigla,
                    :escudo_url,
                    :estadio,
                    :api_updated_at
                )
                ON DUPLICATE KEY UPDATE
                    nome = VALUES(nome),
                    nome_curto = VALUES(nome_curto),
                    sigla = VALUES(sigla),
                    escudo_url = VALUES(escudo_url),
                    estadio = VALUES(estadio),
                    api_updated_at = VALUES(api_updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':external_id' => (int) $dados['external_id'],
            ':nome' => (string) $dados['nome'],
            ':nome_curto' => (string) ($dados['nome_curto'] ?? $dados['nome']),
            ':sigla' => (string) $dados['sigla'],
            ':escudo_url' => $dados['escudo_url'] ?? null,
            ':estadio' => $dados['estadio'] ?? null,
            ':api_updated_at' => $dados['api_updated_at'] ?? null,
        ]);

        $clube = $this->findByExternalId((int) $dados['external_id']);

        return (int) ($clube['id'] ?? $this->pdo->lastInsertId());
    }

    /**
     * Garante o vínculo do clube na temporada sem sobrescrever ajuste_pontos nem probabilidades.
     */
    public function vincularTemporada(int $temporadaId, int $clubeId): void
    {
        $sql = 'INSERT IGNORE INTO clubes_temporadas (temporada_id, clube_id, ajuste_pontos)
                VALUES (:temporada_id, :clube_id, 0)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':temporada_id' => $temporadaId,
            ':clube_id' => $clubeId,
        ]);
    }
}
