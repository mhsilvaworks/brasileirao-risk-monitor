<?php

namespace App;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private PDO $connection;

    public function __construct(?PDO $connection = null)
    {
        // Permite utilizar uma conexão já criada nos testes.
        if ($connection !== null) {
            $connection->setAttribute(
                PDO::ATTR_ERRMODE,
                PDO::ERRMODE_EXCEPTION
            );

            $connection->setAttribute(
                PDO::ATTR_DEFAULT_FETCH_MODE,
                PDO::FETCH_ASSOC
            );

            // Desabilita prepared statements emulados quando o driver é MySQL.
            if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $connection->setAttribute(
                    PDO::ATTR_EMULATE_PREPARES,
                    false
                );
            }

            $this->connection = $connection;
            return;
        }

        $host = $this->getEnv('DB_HOST');
        $port = $this->getEnv('DB_PORT');
        $database = $this->getEnv('DB_DATABASE');
        $username = $this->getEnv('DB_USERNAME');
        $password = $this->getEnv('DB_PASSWORD');

        if (!filter_var($port, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 65535
            ]
        ])) {
            throw new RuntimeException(
                'A variável DB_PORT é inválida.'
            );
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        try {
            $this->connection = new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException) {
            throw new RuntimeException(
                'Não foi possível estabelecer conexão com o banco de dados.'
            );
        }
    }

    private function getEnv(string $name): string
    {
        $value = getenv($name);

        if ($value === false || trim($value) === '') {
            throw new RuntimeException(
                "A variável de ambiente {$name} não foi configurada."
            );
        }

        return $value;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }
}
