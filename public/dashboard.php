<?php

declare(strict_types=1);

use App\Database;
use App\Repositories\ClubeRepository;
use App\Repositories\PartidaRepository;
use App\Repositories\SyncLogRepository;
use App\Repositories\TemporadaRepository;
use App\Repositories\UsuarioRepository;
use App\Security\SessionGuard;
use App\Services\AuthService;
use App\Services\StandingsService;

require_once __DIR__ . '/../src/bootstrap.php';

SessionGuard::requireAuth();

$usuarioId = (int) SessionGuard::currentUserId();
$temporada = null;
$classificacao = [];
$erroConsulta = false;
$clubeFavorito = null;
$partidasAoVivo = [];
$consultaSidebarIndisponivel = false;
$ultimaSincronizacao = null;
$consultaSincronizacaoIndisponivel = false;

try {
    $pdo = (new Database())->getConnection();
    $clubeRepository = new ClubeRepository($pdo);
    $usuarioRepository = new UsuarioRepository($pdo);
    $authService = new AuthService($usuarioRepository, $clubeRepository);
    $usuario = $authService->getProfile($usuarioId);

    if ($usuario === null) {
        SessionGuard::destroySession();
        header('Location: /index.php', true, 303);
        exit;
    }

    try {
        $temporadaRepository = new TemporadaRepository($pdo);
        $temporada = $temporadaRepository->findActiveByCompetitionCode('BSA');

        if ($temporada !== null) {
            $classificacao = (new StandingsService($pdo))
                ->getStandingsByTemporada((int) $temporada['id']);
        }
    } catch (Throwable) {
        $erroConsulta = true;
    }

    try {
        $partidaRepository = new PartidaRepository($pdo);
        $clubeFavoritoId = $usuario['clube_favorito_id'] ?? null;
        if ($clubeFavoritoId !== null) {
            $clubeFavorito = $clubeRepository->findById((int) $clubeFavoritoId);
            if ($clubeFavorito !== null) {
                $partidasAoVivo = $partidaRepository->findLiveByClub(
                    (int) $clubeFavorito['id']
                );
            }
        }
    } catch (Throwable) {
        $consultaSidebarIndisponivel = true;
        $partidasAoVivo = [];
    }

    try {
        $ultimaSincronizacao = (new SyncLogRepository($pdo))
            ->findLastSuccessful();
    } catch (Throwable) {
        $consultaSincronizacaoIndisponivel = true;
    }
} catch (Throwable) {
    $erroConsulta = true;
    $consultaSidebarIndisponivel = true;
    $consultaSincronizacaoIndisponivel = true;
    $classificacao = [];
}

$escapar = static fn (string $valor): string => htmlspecialchars(
    $valor,
    ENT_QUOTES,
    'UTF-8'
);

$zonaPorPosicao = static function (int $posicao): array {
    if ($posicao === 1) {
        return ['campeao', 'Campeão e Libertadores'];
    }
    if ($posicao <= 6) {
        return ['libertadores', 'Libertadores'];
    }
    if ($posicao <= 12) {
        return ['sulamericana', 'Sul-Americana'];
    }
    if ($posicao >= 17) {
        return ['rebaixamento', 'Rebaixamento'];
    }

    return ['neutra', 'Sem zona de classificação'];
};

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visão Geral | ARENA_</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body>
<div class="app-layout">
    <?php require __DIR__ . '/partials/sidebar.php'; ?>

    <main class="app-content dashboard-content">
        <header class="dashboard-header">
            <p class="dashboard-eyebrow">BRASILEIRÃO SÉRIE A</p>
            <h1>VISÃO GERAL</h1>
            <p>Classificação oficial calculada a partir das partidas finalizadas.</p>
        </header>

        <section class="dashboard-section" aria-labelledby="standings-title">
            <div class="standings-heading">
                <div>
                    <h2 id="standings-title">CLASSIFICAÇÃO</h2>
                    <?php if ($temporada !== null): ?>
                        <p>Temporada <?= (int) $temporada['ano'] ?></p>
                    <?php endif; ?>
                </div>
                <div class="zone-legend" aria-label="Legenda das zonas">
                    <span><i class="zone-dot zone-libertadores"></i> Libertadores</span>
                    <span><i class="zone-dot zone-sulamericana"></i> Sul-Americana</span>
                    <span><i class="zone-dot zone-rebaixamento"></i> Rebaixamento</span>
                </div>
            </div>

            <?php if ($erroConsulta): ?>
                <p class="dashboard-state" role="status">
                    Não foi possível consultar a classificação agora. Tente novamente mais tarde.
                </p>
            <?php elseif ($temporada === null): ?>
                <p class="dashboard-state" role="status">
                    Não há uma temporada vigente da Série A cadastrada para exibir.
                </p>
            <?php elseif ($classificacao === []): ?>
                <p class="dashboard-state" role="status">
                    A temporada está cadastrada, mas ainda não há clubes participantes disponíveis.
                </p>
            <?php else: ?>
                <?php if (count($classificacao) !== 20): ?>
                    <p class="dashboard-notice" role="status">
                        A classificação contém <?= count($classificacao) ?> clubes cadastrados nesta temporada.
                    </p>
                <?php endif; ?>

                <div class="standings-grid">
                    <?php foreach ($classificacao as $clube): ?>
                        <?php
                        [$zona, $nomeZona] = $zonaPorPosicao((int) $clube['posicao']);
                        $nomeClube = (string) ($clube['nome_curto'] ?: $clube['nome']);
                        ?>
                        <a class="standings-card zone-<?= $escapar($zona) ?>"
                           href="/clube.php?clube_id=<?= (int) $clube['clube_id'] ?>"
                           aria-label="Abrir radiografia de <?= $escapar($nomeClube) ?>, <?= (int) $clube['posicao'] ?>º colocado">
                            <div class="standings-card-top">
                                <span class="standings-position"><?= (int) $clube['posicao'] ?>º</span>
                                <span class="standings-zone-label"><?= $escapar($nomeZona) ?></span>
                            </div>
                            <div class="standings-club">
                                <?php if (is_string($clube['escudo_url'] ?? null) && $clube['escudo_url'] !== ''): ?>
                                    <img src="<?= $escapar((string) $clube['escudo_url']) ?>"
                                         alt="" loading="lazy" width="40" height="40">
                                <?php endif; ?>
                                <h3><?= $escapar($nomeClube) ?></h3>
                            </div>
                            <div class="standings-points">
                                <strong><?= (int) $clube['pontos'] ?></strong>
                                <span>pontos</span>
                            </div>
                            <dl class="standings-stats">
                                <div><dt>J</dt><dd><?= (int) $clube['jogos'] ?></dd></div>
                                <div><dt>V</dt><dd><?= (int) $clube['vitorias'] ?></dd></div>
                                <div><dt>E</dt><dd><?= (int) $clube['empates'] ?></dd></div>
                                <div><dt>D</dt><dd><?= (int) $clube['derrotas'] ?></dd></div>
                                <div><dt>GP</dt><dd><?= (int) $clube['gols_pro'] ?></dd></div>
                                <div><dt>GC</dt><dd><?= (int) $clube['gols_contra'] ?></dd></div>
                                <div><dt>SG</dt><dd><?= (int) $clube['saldo_gols'] ?></dd></div>
                            </dl>
                            <span class="standings-open">Abrir radiografia <span aria-hidden="true">→</span></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <?php require __DIR__ . '/partials/footer.php'; ?>
</div>
</body>
</html>
