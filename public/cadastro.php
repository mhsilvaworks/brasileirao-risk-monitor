<?php

declare(strict_types=1);

use App\Database;
use App\Repositories\UsuarioRepository;
use App\Repositories\ClubeRepository;
use App\Security\CsrfToken;
use App\Security\SessionGuard;
use App\Services\AuthService;

// 1. Carrega as dependências e inicia a sessão.
require_once __DIR__ . '/../src/bootstrap.php';

// 2. Impede que usuários autenticados acessem o cadastro.
if (SessionGuard::currentUserId() !== null) {
    header('Location: /perfil.php');
    exit;
}

// 3. Inicializa as variáveis utilizadas na página.
$erro = '';
$nome = '';
$email = '';
$clubeSelecionado = '';
$clubes = [];
$disponivel = false;

try {
    // 4. Estabelece a conexão com o banco de dados.
    $pdo = (new Database())->getConnection();

    // Cria os repositórios responsáveis pela persistência.
    $usuarioRepository = new UsuarioRepository($pdo);
    $clubeRepository = new ClubeRepository($pdo);

    // Cria o serviço responsável pelas regras de cadastro.
    $authService = new AuthService(
        $usuarioRepository,
        $clubeRepository
    );

    // Consulta os clubes disponíveis no banco de dados.
    $clubes = $clubeRepository->findAll();
    $disponivel = true;

    // 5. Processa o formulário quando ele é enviado.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Recebe os dados informados pelo usuário.
        $nome = is_string($_POST['nome'] ?? null)
            ? trim($_POST['nome'])
            : '';

        $email = is_string($_POST['email'] ?? null)
            ? trim($_POST['email'])
            : '';

        $senha = is_string($_POST['senha'] ?? null)
            ? $_POST['senha']
            : '';

        $confirmacao = is_string($_POST['confirmacao_senha'] ?? null)
            ? $_POST['confirmacao_senha']
            : '';

        $clubeSelecionado = $_POST['clube_favorito_id'] ?? '';

        $token = is_string($_POST['csrf_token'] ?? null)
            ? $_POST['csrf_token']
            : null;

        // Valida a segurança da requisição.
        if (!CsrfToken::validate($token)) {

            http_response_code(403);
            $erro = 'Solicitação inválida. Tente novamente.';

        } elseif ($senha !== $confirmacao) {

            $erro = 'As senhas não coincidem.';

        } else {

            // O clube favorito é opcional.
            $clubeFavoritoId = null;

            // Valida o identificador do clube selecionado.
            if ($clubeSelecionado !== '') {

                $clubeFavoritoId = filter_var(
                    $clubeSelecionado,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );

                if ($clubeFavoritoId === false) {
                    throw new InvalidArgumentException(
                        'Clube favorito inválido.'
                    );
                }
            }

            // Cadastra o usuário e recupera seu identificador.
            $usuarioId = $authService->register(
                $nome,
                $email,
                $senha,
                $clubeFavoritoId
            );

            // 6. Autentica o usuário após o cadastro.
            SessionGuard::regenerateSession();

            $_SESSION['user_id'] = $usuarioId;

            // Remove o token utilizado antes da autenticação.
            unset($_SESSION['csrf_token']);

            // Redireciona para a área autenticada.
            header('Location: /perfil.php', true, 303);
            exit;
        }
    }

} catch (InvalidArgumentException $e) {

    // Exibe mensagens relacionadas à validação dos dados.
    $erro = $e->getMessage();

} catch (Throwable $e) {

    // Evita expor informações internas do sistema.
    http_response_code(500);
    $erro = 'Não foi possível concluir o cadastro.';
}

// 7. Gera ou recupera o token de segurança.
$csrfToken = CsrfToken::generate();

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Cadastro | ARENA_</title>

    <link rel="stylesheet" href="/assets/css/auth.css">
</head>

<body class="auth-page">

    <main class="auth-card auth-card--register">

        <!-- Identidade visual do projeto -->
        <header class="auth-header">
            <h1>ARENA_</h1>
            <p>SEU JOGO. SEU PAINEL.</p>
        </header>

        <div class="auth-content">

            <h2>CRIAR CONTA</h2>

            <!-- Mensagens de erro -->
            <?php if ($erro !== ''): ?>

                <p role="alert">
                    <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
                </p>

            <?php endif; ?>

            <!-- Exibe o formulário se o banco estiver disponível -->
            <?php if ($disponivel): ?>

                <form method="POST" action="/cadastro.php">

                    <!-- Token de proteção CSRF -->
                    <input type="hidden"
                           name="csrf_token"
                           value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <!-- Nome completo -->
                    <div>
                        <label for="nome">Nome Completo</label>

                        <input type="text"
                               id="nome"
                               name="nome"
                               placeholder="Seu nome completo"
                               maxlength="120"
                               autocomplete="name"
                               value="<?= htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') ?>"
                               required>
                    </div>

                    <!-- E-mail -->
                    <div>
                        <label for="email">E-mail</label>

                        <input type="email"
                               id="email"
                               name="email"
                               placeholder="ribamar@vasco.com"
                               maxlength="190"
                               autocomplete="email"
                               value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                               required>
                    </div>

                    <!-- Senha -->
                    <div>
                        <label for="senha">Senha</label>

                        <input type="password"
                               id="senha"
                               name="senha"
                               placeholder="Digite sua senha"
                               minlength="8"
                               maxlength="72"
                               autocomplete="new-password"
                               required>
                    </div>

                    <!-- Confirmação de senha -->
                    <div>
                        <label for="confirmacao_senha">
                            Confirmar senha
                        </label>

                        <input type="password"
                               id="confirmacao_senha"
                               name="confirmacao_senha"
                               placeholder="Digite sua senha novamente"
                               minlength="8"
                               maxlength="72"
                               autocomplete="new-password"
                               required>
                    </div>

                    <!-- Seleção do clube favorito -->
                    <div>
                        <label for="clube_favorito_id">
                            Clube do Coração (opcional)
                        </label>

                        <select id="clube_favorito_id"
                                name="clube_favorito_id">

                            <option value="">
                                Selecione seu clube
                            </option>

                            <?php foreach ($clubes as $clube): ?>

                                <option
                                    value="<?= (int) $clube['id'] ?>"
                                    <?= is_string($clubeSelecionado)
                                        && $clubeSelecionado === (string) $clube['id']
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= htmlspecialchars(
                                        $clube['nome'] . ' - ' . $clube['sigla'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>
                    </div>

                    <button type="submit" class="auth-button">
                        ▶ CADASTRAR E ENTRAR
                    </button>

                </form>

            <?php endif; ?>

            <!-- Navegação para o login -->
            <p class="auth-footer">
                Já tem conta?
                <a href="/index.php">Voltar ao Login</a>
            </p>

        </div>

    </main>

</body>

</html>