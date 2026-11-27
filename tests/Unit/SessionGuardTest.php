<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\SessionGuard;
use PHPUnit\Framework\TestCase;

final class SessionGuardTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        session_start();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    public function testReturnsNullWhenUserIsNotAuthenticated(): void
    {
        $this->assertNull(SessionGuard::currentUserId());
    }

    public function testReturnsAuthenticatedUserId(): void
    {
        $_SESSION['user_id'] = 42;

        $this->assertSame(42, SessionGuard::currentUserId());
    }

    public function testReturnsNullWhenUserIdIsMissing(): void
    {
        unset($_SESSION['user_id']);

        $this->assertNull(SessionGuard::currentUserId());
    }

    public function testReturnsNullWhenUserIdIsNotAnInteger(): void
    {
        $_SESSION['user_id'] = '42';

        $this->assertNull(SessionGuard::currentUserId());
    }

    public function testReturnsNullWhenUserIdIsNotPositive(): void
    {
        $_SESSION['user_id'] = 0;

        $this->assertNull(SessionGuard::currentUserId());
    }

    public function testRegenerateSessionChangesSessionId(): void
    {
        $oldSessionId = session_id();

        SessionGuard::regenerateSession();

        $this->assertNotSame($oldSessionId, session_id());
    }

    public function testDestroySessionClearsDataAndEndsSession(): void
    {
        $_SESSION['user_id'] = 42;
        $_SESSION['temporary_data'] = 'valor';

        SessionGuard::destroySession();

        $this->assertSame([], $_SESSION);
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testRegenerateSessionThrowsWhenSessionIsInactive(): void
    {
        session_destroy();

        $this->expectException(\LogicException::class);

        SessionGuard::regenerateSession();
    }
}