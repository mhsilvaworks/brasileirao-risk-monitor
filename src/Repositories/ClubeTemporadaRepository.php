<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ClubeTemporadaRepositoryInterface;
use InvalidArgumentException;
use PDO;

/**
 * Repositório responsável pela persistência em `clubes_temporadas`.
 * Centraliza a gravação e leitura das probabilidades calculadas pelo motor PHP.
 */
class ClubeTemporadaRepository implements ClubeTemporadaRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function upsertProbabilities(int $temporadaId, int $clubeId, array $probabilidades): void
    {
        if ($temporadaId <= 0 || $clubeId <= 0) {
            throw new InvalidArgumentException('temporada_id e clube_id devem ser inteiros positivos.');
        }

        $posicoesJson = null;
        if (isset($probabilidades['probabilidades_posicoes']) && $probabilidades['probabilidades_posicoes'] !== null) {
            $encoded = json_encode($probabilidades['probabilidades_posicoes'], JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new InvalidArgumentException('Falha ao serializar probabilidades_posicoes para JSON.');
            }
            $posicoesJson = $encoded;
        }

        $sql = <<<'SQL'
            INSERT INTO clubes_temporadas (
                temporada_id,
                clube_id,
                probabilidade_campeao,
                probabilidade_libertadores,
                probabilidade_sulamericana,
                probabilidade_rebaixamento,
                probabilidades_posicoes,
                probabilidades_calculadas_em
            ) VALUES (
                :temporada_id,
                :clube_id,
                :probabilidade_campeao,
                :probabilidade_libertadores,
                :probabilidade_sulamericana,
                :probabilidade_rebaixamento,
                :probabilidades_posicoes,
                :probabilidades_calculadas_em
            )
            ON DUPLICATE KEY UPDATE
                probabilidade_campeao = VALUES(probabilidade_campeao),
                probabilidade_libertadores = VALUES(probabilidade_libertadores),
                probabilidade_sulamericana = VALUES(probabilidade_sulamericana),
                probabilidade_rebaixamento = VALUES(probabilidade_rebaixamento),
                probabilidades_posicoes = VALUES(probabilidades_posicoes),
                probabilidades_calculadas_em = VALUES(probabilidades_calculadas_em)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':temporada_id' => $temporadaId,
            ':clube_id' => $clubeId,
            ':probabilidade_campeao' => $probabilidades['probabilidade_campeao'] ?? null,
            ':probabilidade_libertadores' => $probabilidades['probabilidade_libertadores'] ?? null,
            ':probabilidade_sulamericana' => $probabilidades['probabilidade_sulamericana'] ?? null,
            ':probabilidade_rebaixamento' => $probabilidades['probabilidade_rebaixamento'] ?? null,
            ':probabilidades_posicoes' => $posicoesJson,
            ':probabilidades_calculadas_em' => $probabilidades['probabilidades_calculadas_em'] ?? null,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function findByTemporadaAndClube(int $temporadaId, int $clubeId): ?array
    {
        $sql = <<<'SQL'
            SELECT
                id,
                temporada_id,
                clube_id,
                ajuste_pontos,
                motivo_ajuste,
                probabilidade_campeao,
                probabilidade_libertadores,
                probabilidade_sulamericana,
                probabilidade_rebaixamento,
                probabilidades_posicoes,
                probabilidades_calculadas_em
            FROM clubes_temporadas
            WHERE temporada_id = :temporada_id
              AND clube_id = :clube_id
            LIMIT 1
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':temporada_id' => $temporadaId,
            ':clube_id' => $clubeId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->hydrateRow($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findByTemporada(int $temporadaId): array
    {
        $sql = <<<'SQL'
            SELECT
                ct.id,
                ct.temporada_id,
                ct.clube_id,
                c.external_id,
                c.nome,
                c.nome_curto,
                c.sigla,
                c.escudo_url,
                ct.ajuste_pontos,
                ct.motivo_ajuste,
                ct.probabilidade_campeao,
                ct.probabilidade_libertadores,
                ct.probabilidade_sulamericana,
                ct.probabilidade_rebaixamento,
                ct.probabilidades_posicoes,
                ct.probabilidades_calculadas_em
            FROM clubes_temporadas ct
            INNER JOIN clubes c ON c.id = ct.clube_id
            WHERE ct.temporada_id = :temporada_id
            ORDER BY c.nome_curto ASC
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':temporada_id' => $temporadaId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => $this->hydrateRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array
    {
        $posicoes = null;
        if (isset($row['probabilidades_posicoes']) && is_string($row['probabilidades_posicoes']) && $row['probabilidades_posicoes'] !== '') {
            $decoded = json_decode($row['probabilidades_posicoes'], true);
            if (is_array($decoded)) {
                $posicoes = $decoded;
            }
        }

        return [
            'id' => (int) $row['id'],
            'temporada_id' => (int) $row['temporada_id'],
            'clube_id' => (int) $row['clube_id'],
            'external_id' => isset($row['external_id']) ? (int) $row['external_id'] : null,
            'nome' => $row['nome'] ?? null,
            'nome_curto' => $row['nome_curto'] ?? null,
            'sigla' => $row['sigla'] ?? null,
            'escudo_url' => $row['escudo_url'] ?? null,
            'ajuste_pontos' => (int) ($row['ajuste_pontos'] ?? 0),
            'motivo_ajuste' => $row['motivo_ajuste'] ?? null,
            'probabilidade_campeao' => $row['probabilidade_campeao'] !== null ? (float) $row['probabilidade_campeao'] : null,
            'probabilidade_libertadores' => $row['probabilidade_libertadores'] !== null ? (float) $row['probabilidade_libertadores'] : null,
            'probabilidade_sulamericana' => $row['probabilidade_sulamericana'] !== null ? (float) $row['probabilidade_sulamericana'] : null,
            'probabilidade_rebaixamento' => $row['probabilidade_rebaixamento'] !== null ? (float) $row['probabilidade_rebaixamento'] : null,
            'probabilidades_posicoes' => $posicoes,
            'probabilidades_calculadas_em' => $row['probabilidades_calculadas_em'] ?? null,
        ];
    }
}