<?php

declare(strict_types=1);

use App\Security\CsrfToken;

// Identifica a página atual para destacar o item do menu.
$paginaAtual = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Recupera o token de segurança utilizado no logout.
$tokenLogout = CsrfToken::generate();

?>

<aside class="sidebar">

    <!-- Identidade visual do ARENA_ -->
    <header class="sidebar-brand">
        <h1>ARENA_</h1>
        <p>SEU JOGO. SEU PAINEL.</p>
    </header>

    <!-- Menu de navegação -->
    <nav class="sidebar-nav" aria-label="Menu principal">

        <!-- Dashboard: será integrado na Issue #15 -->
        <span class="sidebar-link" aria-disabled="true">
            <span class="sidebar-number">01</span>
            Visão geral
        </span>

        <!-- Página de perfil -->
        <a href="/perfil.php"
           class="sidebar-link <?= $paginaAtual === 'perfil.php' ? 'active' : '' ?>"
           <?= $paginaAtual === 'perfil.php' ? 'aria-current="page"' : '' ?>>

            <span class="sidebar-number">02</span>
            Meu perfil
        </a>

        <!-- Encerramento seguro da sessão -->
        <form method="POST" action="/logout.php">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= htmlspecialchars($tokenLogout, ENT_QUOTES, 'UTF-8') ?>">

            <button type="submit" class="sidebar-link">
                <span class="sidebar-number">03</span>
                Sair
            </button>

        </form>

    </nav>

    <!-- Parte inferior da sidebar -->
    <div class="sidebar-bottom">

        <!-- Espaço reservado para futuras partidas ao vivo -->
        <section class="live-score">

            <div class="live-score-header">
                AO VIVO
            </div>

            <div class="live-score-content">
                Placar indisponível
            </div>

            <div class="live-score-footer">
                Aguardando integração
            </div>

        </section>

        <div class="sidebar-season">
            TEMPORADA 2026
        </div>

    </div>

</aside>
