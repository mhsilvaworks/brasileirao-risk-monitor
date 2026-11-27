<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

/**
 * Consulta partidas persistidas sem iniciar sincronizações externas.
 */
class PartidaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Lista partidas ao vivo do clube na temporada vigente da Série A.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findLiveByClub(int $clubeId): array
    {
        if ($clubeId <= 0) {
            throw new InvalidArgumentException(
                'O ID do clube deve ser maior que zero.'
            );
        }

        $dataAtual = (new DateTimeImmutable(
            'now',
            new DateTimeZone('America/Sao_Paulo')
        ))->format('Y-m-d');

        $stmt = $this->pdo->prepare(
            "SELECT p.id,
                    p.status,
                    p.gols_mandante,
                    p.gols_visitante,
                    p.api_updated_at,
                    mandante.id AS mandante_id,
                    mandante.nome AS mandante_nome,
                    visitante.id AS visitante_id,
                    visitante.nome AS visitante_nome
               FROM partidas p
               INNER JOIN clubes mandante ON mandante.id = p.mandante_id
               INNER JOIN clubes visitante ON visitante.id = p.visitante_id
               INNER JOIN temporadas t ON t.id = p.temporada_id
               INNER JOIN competicoes c ON c.id = t.competicao_id
              WHERE (p.mandante_id = :mandante_id
                     OR p.visitante_id = :visitante_id)
                AND p.status IN ('IN_PLAY', 'PAUSED')
                AND t.data_inicio <= :data_inicio
                AND t.data_fim >= :data_fim
                AND c.codigo = :codigo_competicao
              ORDER BY p.data_hora_utc ASC, p.id ASC"
        );

        $stmt->execute([
            ':mandante_id' => $clubeId,
            ':visitante_id' => $clubeId,
            ':data_inicio' => $dataAtual,
            ':data_fim' => $dataAtual,
            ':codigo_competicao' => 'BSA',
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
