<?php

declare(strict_types=1);

use App\Database;
use App\Repositories\UsuarioRepository;
use App\Security\CsrfToken;
use App\Security\SessionGuard;
use App\Services\AuthService;

// 1. Carrega as dependências e inicia a sessão.
require_once __DIR__ . '/../src/bootstrap.php';

// 2. Redireciona usuários que já estão autenticados.
if (SessionGuard::currentUserId() !== null) {
    header('Location: /perfil.php');
    exit;
}

// 3. Inicializa as variáveis utilizadas na página.
$erro = '';
$email = '';

// 4. Processa o formulário quando o usuário tenta entrar.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Recebe os dados enviados pelo formulário.
    $email = is_string($_POST['email'] ?? null)
        ? trim($_POST['email'])
        : '';

    $senha = is_string($_POST['senha'] ?? null)
        ? $_POST['senha']
        : '';

    $token = is_string($_POST['csrf_token'] ?? null)
        ? $_POST['csrf_token']
        : null;

    // Verifica se a solicitação pertence à sessão atual.
    if (!CsrfToken::validate($token)) {

        http_response_code(403);
        $erro = 'Solicitação inválida. Tente novamente.';

    } else {

        try {
            // Estabelece a conexão com o banco de dados.
            $pdo = (new Database())->getConnection();

            // Cria o repositório e o serviço de autenticação.
            $usuarioRepository = new UsuarioRepository($pdo);
            $authService = new AuthService($usuarioRepository);

            // Verifica as credenciais informadas.
            $usuarioId = $authService->authenticate($email, $senha);

            if ($usuarioId === null) {

                $erro = 'E-mail ou senha inválidos.';

            } else {

                // Renova o identificador da sessão por segurança.
                SessionGuard::regenerateSession();

                // Registra o usuário autenticado na sessão.
                $_SESSION['user_id'] = $usuarioId;

                // Remove o token anterior à autenticação.
                unset($_SESSION['csrf_token']);

                // Redireciona para a área autenticada.
                header('Location: /perfil.php', true, 303);
                exit;
            }

        } catch (Throwable $e) {

            // Evita expor informações internas ao usuário.
            http_response_code(500);
            $erro = 'Não foi possível realizar o login.';
        }
    }
}

// 5. Gera ou recupera o token de segurança do formulário.
$csrfToken = CsrfToken::generate();

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Login | ARENA_</title>

    <link rel="stylesheet" href="/assets/css/auth.css">
</head>

<body class="auth-page">

    <main class="auth-card">

        <!-- Identidade visual do projeto -->
        <header class="auth-header">
            <h1>ARENA_</h1>
            <p>SEU JOGO. SEU PAINEL.</p>
        </header>

        <div class="auth-content">

            <h2>ACESSAR PAINEL</h2>

            <!-- Exibe uma mensagem somente quando existe erro -->
            <?php if ($erro !== ''): ?>

                <p role="alert">
                    <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
                </p>

            <?php endif; ?>

            <!-- Formulário de autenticação -->
            <form method="POST" action="/index.php">

                <!-- Token de proteção CSRF -->
                <input type="hidden"
                       name="csrf_token"
                       value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <!-- Campo de e-mail -->
                <div>
                    <label for="email">E-mail</label>

                    <input type="email"
                           id="email"
                           name="email"
                           placeholder="flavio-caça-rato@exemplo.com"
                           autocomplete="email"
                           maxlength="190"
                           value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                           required>
                </div>

                <!-- Campo de senha -->
                <div>
                    <label for="senha">Senha</label>

                    <input type="password"
                           id="senha"
                           name="senha"
                           placeholder="Digite sua senha"
                           autocomplete="current-password"
                           required>
                </div>

                <button type="submit" class="auth-button">
                    ▶ ENTRAR
                </button>

            </form>

            <!-- Navegação para o cadastro -->
            <p class="auth-footer">
                Primeiro acesso?
                <a href="/cadastro.php">Criar conta de torcedor</a>
            </p>

        </div>

    </main>

</body>

</html>