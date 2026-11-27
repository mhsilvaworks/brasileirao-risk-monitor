<?php

declare(strict_types=1);

use App\Database;
use App\Repositories\UsuarioRepository;
use App\Repositories\ClubeRepository;
use App\Security\CsrfToken;
use App\Security\SessionGuard;
use App\Services\AuthService;

// 1. Inicializa a aplicação e protege a página.
require_once __DIR__ . '/../src/bootstrap.php';

SessionGuard::requireAuth();

// O identificador vem da sessão, nunca do formulário.
$usuarioId = SessionGuard::currentUserId();

// 2. Inicializa as variáveis da página.
$erro = '';
$sucesso = '';
$usuario = null;
$clubes = [];

// Recupera um campo enviado pelo formulário.
function campoPost(string $campo): string
{
    return is_string($_POST[$campo] ?? null)
        ? $_POST[$campo]
        : '';
}

// Protege a exibição de dados no HTML.
function escapar(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

try {
    // 3. Estabelece a conexão com o banco de dados.
    $pdo = (new Database())->getConnection();

    $usuarioRepository = new UsuarioRepository($pdo);
    $clubeRepository = new ClubeRepository($pdo);

    $authService = new AuthService(
        $usuarioRepository,
        $clubeRepository
    );

    // Recupera os dados do usuário autenticado.
    $usuario = $authService->getProfile($usuarioId);

    // Encerra a sessão caso a conta não exista mais.
    if ($usuario === null) {
        SessionGuard::destroySession();

        header('Location: /index.php');
        exit;
    }

    // Carrega os clubes cadastrados no banco.
    $clubes = $clubeRepository->findAll();

    // 4. Processa as operações enviadas pelos formulários.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Todas as operações exigem um token CSRF válido.
        if (!CsrfToken::validate(campoPost('csrf_token'))) {

            http_response_code(403);
            $erro = 'Solicitação inválida. Tente novamente.';

        } else {

            // Identifica qual operação foi solicitada.
            switch (campoPost('acao')) {

                // Atualização dos dados pessoais.
                case 'atualizar_perfil':

                    $clubeFavoritoId = null;
                    $clubeSelecionado = $_POST['clube_favorito_id'] ?? '';

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

                    $authService->updateProfile(
                        $usuarioId,
                        campoPost('nome'),
                        $usuario['email'],
                        $clubeFavoritoId
                    );

                    $sucesso = 'Perfil atualizado com sucesso.';
                    break;

                // Atualização do endereço de e-mail.
                case 'alterar_email':

                    $clubeFavoritoId = $usuario['clube_favorito_id'];

                    $authService->updateProfile(
                        $usuarioId,
                        $usuario['nome'],
                        campoPost('email'),
                        $clubeFavoritoId !== null
                            ? (int) $clubeFavoritoId
                            : null
                    );

                    $sucesso = 'E-mail atualizado com sucesso.';
                    break;

                // Alteração da senha.
                case 'alterar_senha':

                    $novaSenha = campoPost('nova_senha');

                    if ($novaSenha !== campoPost('confirmacao_senha')) {
                        throw new InvalidArgumentException(
                            'As senhas não coincidem.'
                        );
                    }

                    $authService->changePassword(
                        $usuarioId,
                        campoPost('senha_atual'),
                        $novaSenha
                    );

                    // Renova a sessão após alterar a senha.
                    SessionGuard::regenerateSession();
                    unset($_SESSION['csrf_token']);

                    $sucesso = 'Senha alterada com sucesso.';
                    break;

                // Exclusão definitiva da conta.
                case 'excluir_conta':

                    if (campoPost('confirmar_exclusao') !== '1') {
                        throw new InvalidArgumentException(
                            'Confirme a exclusão da conta.'
                        );
                    }

                    $authService->deleteAccount(
                        $usuarioId,
                        campoPost('senha_atual')
                    );

                    SessionGuard::destroySession();

                    header('Location: /index.php', true, 303);
                    exit;

                // Rejeita operações desconhecidas.
                default:

                    http_response_code(400);
                    $erro = 'Operação inválida.';
            }

            // Atualiza os dados apresentados na página.
            $usuario = $authService->getProfile($usuarioId);

            if ($usuario === null) {
                SessionGuard::destroySession();

                header('Location: /index.php', true, 303);
                exit;
            }
        }
    }

} catch (InvalidArgumentException $e) {

    // Apresenta mensagens de validação.
    $erro = $e->getMessage();

} catch (Throwable $e) {

    // Não expõe informações internas do sistema.
    http_response_code(500);
    $erro = 'Não foi possível concluir a operação.';
}

// 5. Recupera o token utilizado pelos formulários.
$csrfToken = CsrfToken::generate();

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Meu perfil | ARENA_</title>

    <link rel="stylesheet" href="/assets/css/perfil.css">
</head>

<body>

    <div class="app-layout">

        <!-- Componente compartilhado de navegação -->
        <?php require __DIR__ . '/partials/sidebar.php'; ?>

        <main class="app-content">

            <!-- Cabeçalho da página -->
            <header class="profile-header">

                <p class="profile-breadcrumb">
                    CONTA / MEU PERFIL
                </p>

                <h1>MEU PERFIL</h1>

                <p class="profile-description">
                    Gerencie seus dados e o seu clube do coração.
                </p>

            </header>

            <!-- Mensagens de erro e sucesso -->
            <?php if ($erro !== ''): ?>

                <p class="profile-message" role="alert">
                    <?= escapar($erro) ?>
                </p>

            <?php endif; ?>

            <?php if ($sucesso !== ''): ?>

                <p class="profile-message" role="status">
                    <?= escapar($sucesso) ?>
                </p>

            <?php endif; ?>

            <?php if ($usuario !== null): ?>

                <!-- CARTÃO 1: DADOS DO TORCEDOR -->
                <section class="profile-card">

                    <h2>DADOS DO TORCEDOR</h2>

                    <form method="POST"
                          action="/perfil.php"
                          class="profile-form">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= escapar($csrfToken) ?>">

                        <input type="hidden"
                               name="acao"
                               value="atualizar_perfil">

                        <!-- Nome ocupa toda a largura -->
                        <div class="full-width">

                            <label for="nome">Nome Completo</label>

                            <input type="text"
                                   id="nome"
                                   name="nome"
                                   class="profile-input"
                                   maxlength="120"
                                   autocomplete="name"
                                   value="<?= escapar($usuario['nome']) ?>"
                                   required>

                        </div>

                        <!-- Abre a janela de alteração de senha -->
                        <div>

                            <label for="abrir-senha">Nova Senha</label>

                            <button type="button"
                                    id="abrir-senha"
                                    class="profile-input password-trigger"
                                    data-modal="modal-senha">

                                Digite uma nova senha

                            </button>

                        </div>

                        <!-- Seleção do clube favorito -->
                        <div>

                            <label for="clube_favorito_id">
                                Clube Favorito
                            </label>

                            <select id="clube_favorito_id"
                                    name="clube_favorito_id"
                                    class="profile-input">

                                <option value="">
                                    Nenhum clube selecionado
                                </option>

                                <?php foreach ($clubes as $clube): ?>

                                    <option
                                        value="<?= (int) $clube['id'] ?>"
                                        <?= (int) $usuario['clube_favorito_id']
                                            === (int) $clube['id']
                                            ? 'selected'
                                            : '' ?>
                                    >

                                        <?= escapar(
                                            $clube['nome'] . ' - ' . $clube['sigla']
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <!-- Ações do cartão -->
                        <div class="profile-actions full-width">

                            <button type="submit"
                                    class="profile-button">

                                SALVAR ALTERAÇÕES

                            </button>

                            <button type="button"
                                    class="text-button"
                                    data-modal="modal-email">

                                Alterar e-mail

                            </button>

                        </div>

                    </form>

                </section>

                <!-- CARTÃO 2: ZONA DE PERIGO -->
                <section class="profile-card danger-zone">

                    <div>

                        <h2>ZONA DE PERIGO</h2>

                        <p>A exclusão da conta é permanente.</p>

                        <p>Seus dados não poderão ser recuperados.</p>

                    </div>

                    <button type="button"
                            class="profile-button"
                            data-modal="modal-exclusao">

                        EXCLUIR MINHA CONTA

                    </button>

                </section>

                <!-- MODAL: ALTERAÇÃO DE E-MAIL -->
                <dialog id="modal-email"
                        class="profile-modal"
                        aria-labelledby="titulo-email">

                    <h2 id="titulo-email">ALTERAR E-MAIL</h2>

                    <form method="POST"
                          action="/perfil.php"
                          class="modal-form">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= escapar($csrfToken) ?>">

                        <input type="hidden"
                               name="acao"
                               value="alterar_email">

                        <div>

                            <label for="email">E-mail</label>

                            <input type="email"
                                   id="email"
                                   name="email"
                                   class="profile-input"
                                   maxlength="190"
                                   autocomplete="email"
                                   value="<?= escapar($usuario['email']) ?>"
                                   required>

                        </div>

                        <div class="modal-actions">

                            <button type="button"
                                    class="cancel-button"
                                    data-fechar-modal>
                                Cancelar
                            </button>

                            <button type="submit"
                                    class="profile-button">
                                SALVAR E-MAIL
                            </button>

                        </div>

                    </form>

                </dialog>

                <!-- MODAL: ALTERAÇÃO DE SENHA -->
                <dialog id="modal-senha"
                        class="profile-modal"
                        aria-labelledby="titulo-senha">

                    <h2 id="titulo-senha">ALTERAR SENHA</h2>

                    <form method="POST"
                          action="/perfil.php"
                          class="modal-form">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= escapar($csrfToken) ?>">

                        <input type="hidden"
                               name="acao"
                               value="alterar_senha">

                        <div>

                            <label for="senha_atual">Senha atual</label>

                            <input type="password"
                                   id="senha_atual"
                                   name="senha_atual"
                                   class="profile-input"
                                   autocomplete="current-password"
                                   required>

                        </div>

                        <div>

                            <label for="nova_senha">Nova senha</label>

                            <input type="password"
                                   id="nova_senha"
                                   name="nova_senha"
                                   class="profile-input"
                                   minlength="8"
                                   maxlength="72"
                                   autocomplete="new-password"
                                   required>

                        </div>

                        <div>

                            <label for="confirmacao_senha">
                                Confirmar nova senha
                            </label>

                            <input type="password"
                                   id="confirmacao_senha"
                                   name="confirmacao_senha"
                                   class="profile-input"
                                   minlength="8"
                                   maxlength="72"
                                   autocomplete="new-password"
                                   required>

                        </div>

                        <div class="modal-actions">

                            <button type="button"
                                    class="cancel-button"
                                    data-fechar-modal>
                                Cancelar
                            </button>

                            <button type="submit"
                                    class="profile-button">
                                SALVAR SENHA
                            </button>

                        </div>

                    </form>

                </dialog>

                <!-- MODAL: EXCLUSÃO DA CONTA -->
                <dialog id="modal-exclusao"
                        class="profile-modal"
                        aria-labelledby="titulo-exclusao">

                    <h2 id="titulo-exclusao">EXCLUIR CONTA</h2>

                    <p>
                        Sua conta e suas simulações serão excluídas
                        permanentemente. Esta ação é irreversível.
                    </p>

                    <form method="POST"
                          action="/perfil.php"
                          class="modal-form">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= escapar($csrfToken) ?>">

                        <input type="hidden"
                               name="acao"
                               value="excluir_conta">

                        <div>

                            <label for="senha_exclusao">
                                Confirme sua senha
                            </label>

                            <input type="password"
                                   id="senha_exclusao"
                                   name="senha_atual"
                                   class="profile-input"
                                   autocomplete="current-password"
                                   required>

                        </div>

                        <label class="confirmation-label">

                            <input type="checkbox"
                                   name="confirmar_exclusao"
                                   value="1"
                                   required>

                            Confirmo que desejo excluir minha conta.

                        </label>

                        <div class="modal-actions">

                            <button type="button"
                                    class="cancel-button"
                                    data-fechar-modal>
                                Cancelar
                            </button>

                            <button type="submit"
                                    class="profile-button">
                                EXCLUIR CONTA
                            </button>

                        </div>

                    </form>

                </dialog>

            <?php endif; ?>

        </main>

    </div>

    <!-- Interações das janelas modais -->
    <script src="/assets/js/auth.js" defer></script>

</body>

</html>