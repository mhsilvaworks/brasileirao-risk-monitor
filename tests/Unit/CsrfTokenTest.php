<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\CsrfToken;
use PHPUnit\Framework\TestCase;

final class CsrfTokenTest extends TestCase
{
    // Prepara uma sessão isolada para cada teste.
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    // Limpa os dados após cada teste.
    protected function tearDown(): void
    {
        unset($_SESSION);
    }

    public function testGeraEArmazenaToken(): void
    {
        $token = CsrfToken::generate();

        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $token
        );

        self::assertSame($token, $_SESSION['csrf_token']);

        self::assertSame($token, CsrfToken::generate());
    }

    public function testAceitaTokenValido(): void
    {
        $token = CsrfToken::generate();

        self::assertTrue(CsrfToken::validate($token));
    }

    public function testRejeitaTokensInvalidos(): void
    {
        CsrfToken::generate();

        self::assertFalse(CsrfToken::validate('token-invalido'));
        self::assertFalse(CsrfToken::validate(null));
        self::assertFalse(CsrfToken::validate(''));
    }

    public function testRejeitaTokenSemSessaoConfigurada(): void
    {
        self::assertFalse(
            CsrfToken::validate('qualquer-token')
        );
    }
}
