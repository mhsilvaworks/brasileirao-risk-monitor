<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

class SyncLogRepository
{
    private const ALLOWED_STATUSES = [
        'success',
        'partial',
        'failed',
    ];

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * Retorna a última sincronização completa finalizada com sucesso.
     *
     * @return array<string, mixed>|null
     */
    public function findLastSuccessful(): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, finalizado_em
               FROM sync_logs
              WHERE tipo = :tipo
                AND status = :status
                AND finalizado_em IS NOT NULL
              ORDER BY finalizado_em DESC, id DESC
              LIMIT 1"
        );

        $stmt->execute([
            ':tipo' => 'sincronizacao',
            ':status' => 'success',
        ]);

        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        return $resultado === false ? null : $resultado;
    }

    /**
     * Cria um registro de sincronização em andamento.
     *
     * @param array<string, mixed> $log
     */
    public function create(array $log): int
    {
        $tipo = trim((string) ($log['tipo'] ?? ''));

        if ($tipo === '' || mb_strlen($tipo) > 40) {
            throw new InvalidArgumentException(
                'O tipo do log deve conter entre 1 e 40 caracteres.'
            );
        }

        $iniciadoEm = $log['iniciado_em'] ?? null;

        if (!$iniciadoEm instanceof DateTimeImmutable) {
            throw new InvalidArgumentException(
                'iniciado_em deve ser uma instância de DateTimeImmutable.'
            );
        }

        $detalhes = $log['detalhes'] ?? [];

        if (!is_array($detalhes)) {
            throw new InvalidArgumentException(
                'Os detalhes do log devem ser um array.'
            );
        }

        $detalhesJson = json_encode(
            $detalhes,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
        );

        $sql = 'INSERT INTO sync_logs (
                    tipo,
                    status,
                    mensagem,
                    registros_processados,
                    iniciado_em,
                    finalizado_em,
                    duracao_ms,
                    detalhes_json
                ) VALUES (
                    :tipo,
                    :status,
                    :mensagem,
                    0,
                    :iniciado_em,
                    NULL,
                    NULL,
                    :detalhes_json
                )';

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            ':tipo' => $tipo,
            ':status' => 'running',
            ':mensagem' => 'Sincronização em andamento.',
            ':iniciado_em' => $iniciadoEm
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s'),
            ':detalhes_json' => $detalhesJson,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Finaliza um registro de sincronização.
     *
     * @param array<string, mixed> $resultado
     */
    public function finish(int $id, array $resultado): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException(
                'O ID do log deve ser maior que zero.'
            );
        }

        $status = $resultado['status'] ?? null;

        if (
            !is_string($status)
            || !in_array($status, self::ALLOWED_STATUSES, true)
        ) {
            throw new InvalidArgumentException(
                'Status final de sincronização inválido.'
            );
        }

        $mensagem = (string) ($resultado['mensagem'] ?? '');
        $registros = (int) ($resultado['registros_processados'] ?? 0);
        $duracao = (int) ($resultado['duracao_ms'] ?? 0);
        $detalhes = $resultado['detalhes'] ?? [];

        if (!is_array($detalhes)) {
            throw new InvalidArgumentException(
                'Os detalhes do log devem ser um array.'
            );
        }

        $detalhesJson = json_encode(
            $detalhes,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
        );

        $mensagem = mb_strlen($mensagem) > 500
            ? mb_substr($mensagem, 0, 497) . '...'
            : $mensagem;

        $finalizadoEm = (new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        ))->format('Y-m-d H:i:s');

        $sql = 'UPDATE sync_logs
                   SET status = :status,
                       mensagem = :mensagem,
                       registros_processados = :registros_processados,
                       finalizado_em = :finalizado_em,
                       duracao_ms = :duracao_ms,
                       detalhes_json = :detalhes_json
                 WHERE id = :id';

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            ':status' => $status,
            ':mensagem' => $mensagem,
            ':registros_processados' => max(0, $registros),
            ':finalizado_em' => $finalizadoEm,
            ':duracao_ms' => max(0, $duracao),
            ':detalhes_json' => $detalhesJson,
            ':id' => $id,
        ]);

        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare(
                'SELECT id FROM sync_logs WHERE id = :id'
            );

            $check->execute([':id' => $id]);

            if ($check->fetchColumn() === false) {
                throw new InvalidArgumentException(
                    'Registro de sincronização não encontrado.'
                );
            }
        }
    }
}
