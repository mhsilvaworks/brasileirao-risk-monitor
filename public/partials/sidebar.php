<?php

declare(strict_types=1);

use App\Security\CsrfToken;

// Identifica a página atual para destacar o item do menu.
$paginaAtual = basename($_SERVER['SCRIPT_NAME'] ?? '');

// A página da Issue #15 ainda não existe na main.
$dashboardDisponivel = is_file(__DIR__ . '/../dashboard.php');

// Recupera o token de segurança utilizado no logout.
$tokenLogout = CsrfToken::generate();

$escaparSidebar = static fn (string $valor): string => htmlspecialchars(
    $valor,
    ENT_QUOTES,
    'UTF-8'
);

?>

<aside class="sidebar">

    <!-- Identidade visual do ARENA_ -->
    <header class="sidebar-brand">
        <h1>ARENA_</h1>
        <p>SEU JOGO. SEU PAINEL.</p>
    </header>

    <!-- Menu de navegação -->
    <nav class="sidebar-nav" aria-label="Menu principal">

        <?php if ($dashboardDisponivel): ?>
            <a href="/dashboard.php"
               class="sidebar-link <?= $paginaAtual === 'dashboard.php' ? 'active' : '' ?>"
               <?= $paginaAtual === 'dashboard.php' ? 'aria-current="page"' : '' ?>>
        <?php else: ?>
            <span class="sidebar-link is-disabled" aria-disabled="true">
        <?php endif; ?>
            <span class="sidebar-number">01</span>
            Visão geral
        <?php if ($dashboardDisponivel): ?></a><?php else: ?></span><?php endif; ?>

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

    <!-- Clube favorito e placar persistido, quando houver partida ao vivo -->
    <div class="sidebar-bottom">

        <?php if ($consultaSidebarIndisponivel ?? false): ?>
            <p class="sidebar-feedback" role="status">
                Não foi possível consultar o clube favorito agora.
            </p>
        <?php elseif (is_array($clubeFavorito ?? null)): ?>
            <section class="favorite-club" aria-label="Clube favorito">
                <span>SEU CLUBE</span>
                <strong><?= $escaparSidebar((string) $clubeFavorito['nome']) ?></strong>
            </section>
        <?php endif; ?>

        <?php foreach (($partidasAoVivo ?? []) as $partida): ?>
            <?php
            $statusPartida = (string) ($partida['status'] ?? '');
            if (!in_array($statusPartida, ['IN_PLAY', 'PAUSED'], true)) {
                continue;
            }

            $dataAtualizacao = $partida['api_updated_at'] ?? null;
            ?>
            <section class="live-score" aria-label="Partida do clube favorito">
                <div class="live-score-header">
                    ÚLTIMO STATUS:
                    <?= $statusPartida === 'PAUSED' ? 'EM PAUSA' : 'AO VIVO' ?>
                </div>

                <div class="live-score-content">
                    <span><?= $escaparSidebar((string) $partida['mandante_nome']) ?></span>
                    <strong>
                        <?= $partida['gols_mandante'] === null
                            ? '—'
                            : (int) $partida['gols_mandante'] ?>
                        :
                        <?= $partida['gols_visitante'] === null
                            ? '—'
                            : (int) $partida['gols_visitante'] ?>
                    </strong>
                    <span><?= $escaparSidebar((string) $partida['visitante_nome']) ?></span>
                </div>

                <div class="live-score-footer">
                    <?php if (is_string($dataAtualizacao) && $dataAtualizacao !== ''): ?>
                        Dado da API atualizado em
                        <?= $escaparSidebar($dataAtualizacao) ?> UTC
                    <?php else: ?>
                        Horário de atualização da API indisponível
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="sidebar-season">
            SÉRIE A
        </div>

    </div>

</aside>
