<?php

declare(strict_types=1);

use App\Security\CsrfToken;
use App\Security\SessionGuard;

require_once __DIR__ . '/../src/bootstrap.php';

SessionGuard::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

$token = is_string($_POST['csrf_token'] ?? null)
    ? $_POST['csrf_token']
    : null;

if (!CsrfToken::validate($token)) {
    http_response_code(403);
    exit('Solicitação inválida.');
}

SessionGuard::destroySession();

header('Location: /index.php', true, 303);
exit;
