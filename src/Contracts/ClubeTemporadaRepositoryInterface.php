<?php

declare(strict_types=1);

namespace App\Contracts;

interface ClubeTemporadaRepositoryInterface
{
    /**
     * Persiste ou atualiza as probabilidades calculadas de um clube na temporada.
     *
     * @param array{
     *     probabilidade_campeao: float|null,
     *     probabilidade_libertadores: float|null,
     *     probabilidade_sulamericana: float|null,
     *     probabilidade_rebaixamento: float|null,
     *     probabilidades_posicoes: array<string, float>|null,
     *     probabilidades_calculadas_em: string|null
     * } $probabilidades
     */
    public function upsertProbabilities(int $temporadaId, int $clubeId, array $probabilidades): void;

    /**
     * Consulta os dados probabilísticos persistidos de um clube em uma temporada.
     *
     * @return array<string, mixed>|null
     */
    public function findByTemporadaAndClube(int $temporadaId, int $clubeId): ?array;

    /**
     * Lista clubes vinculados à temporada com ajuste_pontos e probabilidades persistidas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByTemporada(int $temporadaId): array;
}