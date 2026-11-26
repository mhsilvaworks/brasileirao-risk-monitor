<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

final class Logger
{
    private const LEVEL_INFO = 'INFO';
    private const LEVEL_WARNING = 'WARNING';
    private const LEVEL_ERROR = 'ERROR';

    /**
     * Chaves cujos valores nunca devem aparecer nos logs.
     */
    private const SENSITIVE_KEYS = [
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'password',
        'senha',
        'senha_hash',
        'cookie',
        'set-cookie',
        'session',
        'session_id',
        'api_key',
        'secret',
        'client_secret',
    ];

    /**
     * Padrões para identificar credenciais em mensagens textuais.
     */
    private const SENSITIVE_PATTERNS = [
        '/(authorization\s*[:=]\s*)(?:bearer\s+)?[^\s,;]+/i',
        '/(bearer\s+)[A-Za-z0-9._~+\/=-]+/i',
        '/((?:access_token|refresh_token|api_key|client_secret|password|senha)\s*[:=]\s*)["\']?[^"\'\s,;]+/i',
    ];

    public function __construct(
    private readonly string $outputStream = 'php://stdout',
    private readonly string $errorStream = 'php://stderr',
    ) {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write(self::LEVEL_INFO, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write(self::LEVEL_WARNING, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Registra uma mensagem formatada e sanitizada.
     */
    private function write(
        string $level,
        string $message,
        array $context
    ): void {
        $safeMessage = $this->sanitizeString($message);
        $safeContext = $this->sanitizeContext($context);

        $timestamp = $this->now();

        $entry = [
            'timestamp' => $timestamp->format('Y-m-d\TH:i:s.u\Z'),
            'level' => $level,
            'message' => $safeMessage,
            'context' => $safeContext,
        ];

        try {
            $json = json_encode(
                $entry,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            $json = json_encode([
                'timestamp' => $timestamp->format('Y-m-d\TH:i:s.u\Z'),
                'level' => $level,
                'message' => 'Falha ao formatar mensagem de log.',
                'context' => [],
            ]);
        }

        if ($json === false) {
            $json = '{"level":"ERROR","message":"Falha ao formatar log."}';
        }

        $stream = $level === self::LEVEL_ERROR
            ? $this->errorStream
            : $this->outputStream;

        $this->writeToStream($stream, $json);

    }

    /**
     * Escreve no stdout ou stderr sem permitir que falhas de log
     * interrompam a operação principal.
     */
    private function writeToStream(string $stream, string $message): void
    {
        try {
            $handle = @fopen($stream, 'ab');

            if ($handle === false) {
                return;
            }

            try {
                @fwrite($handle, $message . PHP_EOL);
            } finally {
                @fclose($handle);
            }
        } catch (Throwable) {
            // Falhas na saída de log não devem interromper a aplicação.
        }
    }

    /**
     * Remove informações sensíveis do contexto.
     */
    public function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $normalizedKey = strtolower(
                str_replace(['-', ' '], '_', (string) $key)
            );

            if ($this->isSensitiveKey($normalizedKey)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = $this->sanitizeString($value);
                continue;
            }

            if (
                is_null($value)
                || is_bool($value)
                || is_int($value)
                || is_float($value)
            ) {
                $sanitized[$key] = $value;
                continue;
            }

            // Objetos e recursos não são serializados diretamente.
            $sanitized[$key] = '[UNSUPPORTED_VALUE]';
        }

        return $sanitized;
    }

    /**
     * Verifica se uma chave representa dado sensível.
     */
    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (
                $key === $sensitiveKey
                || str_ends_with($key, '_' . $sensitiveKey)
                || str_starts_with($key, $sensitiveKey . '_')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mascara credenciais encontradas em mensagens.
     */
    public function sanitizeString(string $value): string
    {
        return preg_replace(
            self::SENSITIVE_PATTERNS,
            '$1[REDACTED]',
            $value
        ) ?? '[MESSAGE_REDACTED]';
    }

    /**
     * Retorna a data e hora atual em UTC.
     */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );
    }
}
