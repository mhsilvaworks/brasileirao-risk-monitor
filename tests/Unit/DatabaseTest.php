<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseTest extends TestCase
{
    public function testAcceptsAnInjectedPdoConnection(): void
    {
        $pdo = $this->createMock(PDO::class);

        $pdo->method('getAttribute')
            ->willReturn('mysql');

        $pdo->expects($this->exactly(3))
            ->method('setAttribute')
            ->willReturn(true);

        $database = new Database($pdo);

        $this->assertSame($pdo, $database->getConnection());
    }

    public function testThrowsExceptionWhenEnvironmentVariableIsMissing(): void
    {
        $originalValue = getenv('DB_HOST');

        putenv('DB_HOST');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'A variável de ambiente DB_HOST não foi configurada.'
            );

            new Database();
        } finally {
            if ($originalValue === false) {
                putenv('DB_HOST');
            } else {
                putenv('DB_HOST=' . $originalValue);
            }
        }
    }

    public function testThrowsExceptionWhenPortIsInvalid(): void
    {
        $variables = [
            'DB_HOST' => 'localhost',
            'DB_PORT' => 'invalid',
            'DB_DATABASE' => 'brasileirao_risco',
            'DB_USERNAME' => 'arena_app',
            'DB_PASSWORD' => 'test_password',
        ];

        $originalValues = [];

        foreach ($variables as $name => $value) {
            $originalValues[$name] = getenv($name);
            putenv("$name=$value");
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'A variável DB_PORT é inválida.'
            );

            new Database();
        } finally {
            foreach ($originalValues as $name => $value) {
                if ($value === false) {
                    putenv($name);
                } else {
                    putenv("$name=$value");
                }
            }
        }
    }
}
