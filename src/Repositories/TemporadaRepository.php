<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Consulta temporadas por competição e período registrado no banco. */
class TemporadaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Busca a temporada da competição vigente na data local do campeonato.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByCompetitionCode(string $codigo): ?array
    {
        $dataAtual = (new DateTimeImmutable(
            'now',
            new DateTimeZone('America/Sao_Paulo')
        ))->format('Y-m-d');

        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.ano, t.data_inicio, t.data_fim, t.rodada_atual
               FROM temporadas t
               INNER JOIN competicoes c ON c.id = t.competicao_id
              WHERE c.codigo = :codigo
                AND t.data_inicio <= :data_inicio
                AND t.data_fim >= :data_fim
              ORDER BY t.data_inicio DESC, t.id DESC
              LIMIT 1'
        );
        $stmt->execute([
            ':codigo' => $codigo,
            ':data_inicio' => $dataAtual,
            ':data_fim' => $dataAtual,
        ]);

        $temporada = $stmt->fetch(PDO::FETCH_ASSOC);

        return $temporada === false ? null : $temporada;
    }
}
