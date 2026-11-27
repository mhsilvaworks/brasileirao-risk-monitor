<?php

declare(strict_types=1);

namespace App\Security;

final class SessionGuard
{
    /**
     * Retorna o ID do usuário autenticado ou null.
     */
    public static function currentUserId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $userId = $_SESSION['user_id'] ?? null;

        if (!is_int($userId) || $userId <= 0) {
            return null;
        }

        return $userId;
    }

    /**
     * Impede o acesso de usuários não autenticados.
     */
    public static function requireAuth(): void
    {
        if (self::currentUserId() !== null) {
            return;
        }

        header('Location: /index.php');
        exit;
    }

    /**
     * Regenera o ID da sessão após autenticação bem-sucedida.
     */
    public static function regenerateSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \LogicException(
                'Não é possível regenerar uma sessão que não está ativa.'
            );
        }

        session_regenerate_id(true);
    }

    /**
     * Limpa e encerra a sessão, invalidando também o cookie.
     */
    public static function destroySession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $cookieParams = session_get_cookie_params();

            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookieParams['path'],
                'domain' => $cookieParams['domain'],
                'secure' => $cookieParams['secure'],
                'httponly' => $cookieParams['httponly'],
                'samesite' => $cookieParams['samesite'],
            ]);
        }

        session_destroy();
    }
}
