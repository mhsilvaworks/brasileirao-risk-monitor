<?php

declare(strict_types=1);

use App\Database;
use App\Repositories\ClubeRepository;
use App\Repositories\ClubeTemporadaRepository;
use App\Repositories\PartidaRepository;
use App\Repositories\SyncLogRepository;
use App\Repositories\TemporadaRepository;
use App\Repositories\UsuarioRepository;
use App\Security\SessionGuard;
use App\Services\AuthService;
use App\Services\ProbabilidadeService;

require_once __DIR__ . '/../src/bootstrap.php';

SessionGuard::requireAuth();

$usuarioId = (int) SessionGuard::currentUserId();
$usuario = null;
$temporada = null;
$clubes = [];
$clubeSelecionado = null;
$probabilidades = null;
$estado = 'indisponivel';
$mensagemEstado = '';
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
    $usuario = (new AuthService($usuarioRepository, $clubeRepository))
        ->getProfile($usuarioId);

    if ($usuario === null) {
        SessionGuard::destroySession();
        header('Location: /index.php', true, 303);
        exit;
    }

    try {
        $temporada = (new TemporadaRepository($pdo))
            ->findActiveByCompetitionCode('BSA');

        if ($temporada === null) {
            $mensagemEstado = 'Não há uma temporada vigente da Série A cadastrada.';
        } else {
            $clubes = $clubeRepository->findByTemporada((int) $temporada['id']);
            $clubesPorId = [];
            foreach ($clubes as $clube) {
                $clubesPorId[(int) $clube['id']] = $clube;
            }

            $parametroInformado = array_key_exists('clube_id', $_GET);
            if ($parametroInformado) {
                $idSolicitado = filter_var(
                    $_GET['clube_id'],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($idSolicitado === false) {
                    $mensagemEstado = 'O clube informado não está disponível nesta temporada.';
                } elseif (!isset($clubesPorId[$idSolicitado])) {
                    $mensagemEstado = 'O clube informado não participa desta temporada.';
                } else {
                    $clubeSelecionado = $clubesPorId[$idSolicitado];
                }
            } else {
                $favoritoId = $usuario['clube_favorito_id'] ?? null;
                if ($favoritoId === null) {
                    $mensagemEstado = 'Selecione um clube favorito no seu perfil ou escolha um clube abaixo.';
                } elseif (!isset($clubesPorId[(int) $favoritoId])) {
                    $mensagemEstado = 'Seu clube favorito não está disponível nesta temporada.';
                } else {
                    $clubeSelecionado = $clubesPorId[(int) $favoritoId];
                }
            }

            if ($clubeSelecionado !== null) {
                $clubeTemporadaRepository = new ClubeTemporadaRepository($pdo);
                $probabilidades = (new ProbabilidadeService(
                    null,
                    null,
                    $clubeTemporadaRepository,
                    $pdo
                ))->getClubProbabilities(
                    (int) $temporada['id'],
                    (int) $clubeSelecionado['id']
                );

                $estado = 'disponivel';
            }
        }
    } catch (Throwable) {
        $erroConsulta = true;
        $estado = 'indisponivel';
        $mensagemEstado = 'Não foi possível consultar os dados do clube agora.';
    }

    try {
        $favoritoId = $usuario['clube_favorito_id'] ?? null;
        if ($favoritoId !== null) {
            $clubeFavorito = $clubeRepository->findById((int) $favoritoId);
            if ($clubeFavorito !== null) {
                $partidasAoVivo = (new PartidaRepository($pdo))
                    ->findLiveByClub((int) $clubeFavorito['id']);
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
    $mensagemEstado = 'Não foi possível consultar os dados do clube agora.';
}

$escapar = static fn (string $valor): string => htmlspecialchars(
    $valor,
    ENT_QUOTES,
    'UTF-8'
);

$formatarProbabilidade = static function (mixed $valor): string {
    return $valor === null
        ? '—'
        : number_format((float) $valor, 2, ',', '.') . '%';
};

$probabilidadesPorPosicao = is_array($probabilidades['probabilidades_posicoes'] ?? null)
    ? $probabilidades['probabilidades_posicoes']
    : [];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radiografia do Clube | ARENA_</title>
    <link rel="stylesheet" href="/assets/css/clube.css">
</head>
<body>
<div class="app-layout">
    <?php require __DIR__ . '/partials/sidebar.php'; ?>

    <main class="app-content club-content">
        <header class="club-header">
            <p class="club-eyebrow">VISÃO GERAL / CLUBE</p>
            <h1>RADIOGRAFIA DO CLUBE</h1>
            <p>Probabilidades estimadas a partir dos dados estatísticos persistidos.</p>
        </header>

        <form class="club-picker" method="GET" action="/clube.php">
            <label for="clube_id">CLUBE ANALISADO</label>
            <select id="clube_id" name="clube_id">
                <option value="">Selecione um clube</option>
                <?php foreach ($clubes as $clube): ?>
                    <option value="<?= (int) $clube['id'] ?>"
                        <?= $clubeSelecionado !== null
                            && (int) $clubeSelecionado['id'] === (int) $clube['id']
                                ? 'selected'
                                : '' ?>>
                        <?= $escapar((string) ($clube['nome_curto'] ?: $clube['nome'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">VER CLUBE</button>
        </form>

        <?php if ($estado !== 'disponivel'): ?>
            <p class="club-state" role="status">
                <?= $escapar($mensagemEstado !== ''
                    ? $mensagemEstado
                    : 'Os dados deste clube não estão disponíveis.') ?>
            </p>
        <?php else: ?>
            <section class="club-summary" aria-label="Clube selecionado">
                <?php if (is_string($clubeSelecionado['escudo_url'] ?? null)
                    && $clubeSelecionado['escudo_url'] !== ''): ?>
                    <img src="<?= $escapar((string) $clubeSelecionado['escudo_url']) ?>"
                         alt="" width="64" height="64">
                <?php endif; ?>
                <div>
                    <p>TEMPORADA <?= (int) $temporada['ano'] ?></p>
                    <h2><?= $escapar((string) ($clubeSelecionado['nome'] ?? 'Clube')) ?></h2>
                </div>
            </section>

            <?php if (!($probabilidades['disponivel'] ?? false)): ?>
                <p class="club-state" role="status">
                    Ainda não há probabilidades calculadas para este clube. Os dados oficiais permanecem indisponíveis.
                </p>
            <?php else: ?>
                <section class="probability-section" aria-labelledby="probability-title">
                    <div class="club-section-heading">
                        <h2 id="probability-title">PROBABILIDADES POR ZONA</h2>
                        <p>Estimativas do modelo estatístico</p>
                    </div>
                    <div class="probability-grid">
                        <?php foreach ([
                            'probabilidade_campeao' => 'Campeão',
                            'probabilidade_libertadores' => 'Libertadores',
                            'probabilidade_sulamericana' => 'Sul-Americana',
                            'probabilidade_rebaixamento' => 'Rebaixamento',
                        ] as $campo => $rotulo): ?>
                            <article class="probability-card">
                                <h3><?= $escapar($rotulo) ?></h3>
                                <p><?= $escapar($formatarProbabilidade($probabilidades[$campo] ?? null)) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="positions-section" aria-labelledby="positions-title">
                    <div class="club-section-heading">
                        <h2 id="positions-title">DISTRIBUIÇÃO DE POSIÇÕES</h2>
                        <p>Probabilidade estimada de terminar em cada posição</p>
                    </div>
                    <div class="positions-grid">
                        <?php for ($posicao = 1; $posicao <= 20; $posicao++): ?>
                            <?php $valor = $probabilidadesPorPosicao[(string) $posicao]
                                ?? $probabilidadesPorPosicao[$posicao]
                                ?? null; ?>
                            <div class="position-probability">
                                <span><?= $posicao ?>º</span>
                                <strong><?= $escapar($formatarProbabilidade($valor)) ?></strong>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <?php if (is_string($probabilidades['probabilidades_calculadas_em'] ?? null)): ?>
                        <?php
                        $dataCalculoUtc = new DateTimeImmutable(
                            $probabilidades['probabilidades_calculadas_em'],
                            new DateTimeZone('UTC')
                        );
                        $dataCalculoLocal = $dataCalculoUtc->setTimezone(
                            new DateTimeZone('America/Sao_Paulo')
                        );
                        ?>
                        <p class="calculation-time">
                            Estimativa calculada em
                            <time datetime="<?= $escapar($dataCalculoUtc->format(DateTimeInterface::ATOM)) ?>">
                                <?= $escapar($dataCalculoLocal->format('d/m/Y H:i')) ?>
                            </time>
                        </p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>

    </main>

    <?php require __DIR__ . '/partials/footer.php'; ?>
</div>
</body>
</html>
