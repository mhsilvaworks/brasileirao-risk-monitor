<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private string $outputFile;
    private string $errorFile;

    protected function setUp(): void
    {
        $this->outputFile = tempnam(sys_get_temp_dir(), 'arena-out-');
        $this->errorFile = tempnam(sys_get_temp_dir(), 'arena-err-');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputFile)) {
            unlink($this->outputFile);
        }

        if (file_exists($this->errorFile)) {
            unlink($this->errorFile);
        }
    }

    private function createLogger(): Logger
    {
        return new Logger(
            $this->outputFile,
            $this->errorFile
        );
    }

    public function testRegistraMensagemInfoNoStdout(): void
    {
        $logger = $this->createLogger();

        $logger->info('Sincronização iniciada.');

        $content = file_get_contents($this->outputFile);

        self::assertStringContainsString('"level":"INFO"', $content);
        self::assertStringContainsString(
            'Sincronização iniciada.',
            $content
        );
    }

    public function testRegistraWarningNoStdout(): void
    {
        $logger = $this->createLogger();

        $logger->warning('A API respondeu lentamente.');

        $content = file_get_contents($this->outputFile);

        self::assertStringContainsString('"level":"WARNING"', $content);
    }

    public function testRegistraErrorNoStderr(): void
    {
        $logger = $this->createLogger();

        $logger->error('Falha na sincronização.');

        $content = file_get_contents($this->errorFile);

        self::assertStringContainsString('"level":"ERROR"', $content);
        self::assertStringContainsString(
            'Falha na sincronização.',
            $content
        );
    }

    public function testOcultaDadosSensíveisDoContexto(): void
    {
        $logger = $this->createLogger();

        $logger->error('Erro na API.', [
            'token' => 'segredo123',
            'password' => 'senha123',
            'http_status' => 500,
        ]);

        $content = file_get_contents($this->errorFile);

        self::assertStringContainsString('[REDACTED]', $content);
        self::assertStringNotContainsString('segredo123', $content);
        self::assertStringNotContainsString('senha123', $content);
        self::assertStringContainsString('500', $content);
    }

    public function testOcultaCredenciaisEmMensagens(): void
    {
        $logger = $this->createLogger();

        $logger->error(
            'Falha: Authorization: Bearer abc123xyz'
        );

        $content = file_get_contents($this->errorFile);

        self::assertStringContainsString('[REDACTED]', $content);
        self::assertStringNotContainsString('abc123xyz', $content);
    }

    public function testSanitizaContextosAninhados(): void
    {
        $logger = $this->createLogger();

        $logger->info('Teste.', [
            'api' => [
                'access_token' => 'token-secreto',
                'http_status' => 429,
            ],
        ]);

        $content = file_get_contents($this->outputFile);

        self::assertStringNotContainsString(
            'token-secreto',
            $content
        );

        self::assertStringContainsString('[REDACTED]', $content);
        self::assertStringContainsString('429', $content);
    }

    public function testIncluiTimestampUtc(): void
    {
        $logger = $this->createLogger();

        $logger->info('Teste de horário.');

        $content = file_get_contents($this->outputFile);

        self::assertMatchesRegularExpression(
            '/"timestamp":"\d{4}-\d{2}-\d{2}T.*Z"/',
            $content
        );
    }
}
