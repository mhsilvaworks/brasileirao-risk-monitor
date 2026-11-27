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
use App\Services\StandingsService;

require_once __DIR__ . '/../src/bootstrap.php';

SessionGuard::requireAuth();

$usuarioId = (int) SessionGuard::currentUserId();
$usuario = null;
$temporada = null;
$clubes = [];
$clubeSelecionado = null;
$probabilidades = null;
$classificacaoClube = null;
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

                foreach ((new StandingsService($pdo))->getStandingsByTemporada((int) $temporada['id']) as $linha) {
                    if ($linha['clube_id'] === (int) $clubeSelecionado['id']) {
                        $classificacaoClube = $linha;
                        break;
                    }
                }
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

$posicaoSelecionada = filter_input(INPUT_GET, 'posicao', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 20],
]) ?: ($classificacaoClube['posicao'] ?? 1);

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

        <?php if ($estado === 'disponivel'): ?>
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
        <?php endif; ?>
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
        <?php if ($estado === 'disponivel'): ?>
                <?php if ($classificacaoClube !== null): ?>
                    <dl class="club-stats">
                        <?php foreach ([
                            'Pts' => $classificacaoClube['pontos'],
                            'Pos' => $classificacaoClube['posicao'] . 'º',
                            'Saldo' => sprintf('%+d', $classificacaoClube['saldo_gols']),
                            'Jogos rest.' => max(0, 2 * (count($clubes) - 1) - $classificacaoClube['jogos']),
                            'Aproveit.' => number_format($classificacaoClube['aproveitamento'], 1, ',', '.') . '%',
                        ] as $rotulo => $valor): ?>
                            <div><dt><?= $escapar($rotulo) ?></dt><dd><?= $escapar((string) $valor) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($estado !== 'disponivel'): ?>
            <p class="club-state" role="status">
                <?= $escapar($mensagemEstado !== ''
                    ? $mensagemEstado
                    : 'Os dados deste clube não estão disponíveis.') ?>
            </p>
        <?php else: ?>
            <?php if (!($probabilidades['disponivel'] ?? false)): ?>
                <p class="club-state" role="status">
                    Ainda não há probabilidades calculadas para este clube.
                </p>
            <?php else: ?>
                <section class="probability-section" aria-labelledby="probability-title">
                    <div class="club-section-heading">
                        <h2 id="probability-title">PROBABILIDADES POR ZONA</h2>
                        <p>Estimativas do modelo estatístico</p>
                    </div>
                    <div class="probability-grid">
                        <?php foreach ([
                            'probabilidade_libertadores' => 'Libertadores',
                            'probabilidade_sulamericana' => 'Sul-Americana',
                            'probabilidade_rebaixamento' => 'Rebaixamento',
                            'probabilidade_campeao' => 'Campeão',
                        ] as $campo => $rotulo): ?>
                            <article class="probability-card <?= $escapar($campo) ?>">
                                <h3><?= $escapar($rotulo) ?></h3>
                                <p><?= $escapar($formatarProbabilidade($probabilidades[$campo] ?? null)) ?></p>
                                <?php if (isset($probabilidades[$campo])): ?>
                                    <meter min="0" max="100" value="<?= (float) $probabilidades[$campo] ?>"
                                           aria-label="<?= $escapar($rotulo) ?>"></meter>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="positions-section" aria-labelledby="positions-title">
                    <div class="club-section-heading">
                        <h2 id="positions-title">POSIÇÃO FINAL</h2>
                        <form method="get" action="/clube.php">
                            <input type="hidden" name="clube_id" value="<?= (int) $clubeSelecionado['id'] ?>">
                            <label for="posicao">Ver posição</label>
                            <select id="posicao" name="posicao">
                                <?php for ($i = 1; $i <= 20; $i++): ?>
                                    <option value="<?= $i ?>" <?= $posicaoSelecionada === $i ? 'selected' : '' ?>>
                                        <?= $i ?>º lugar
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <button type="submit">Ver</button>
                        </form>
                        <p><strong><?= $escapar($formatarProbabilidade($probabilidadesPorPosicao[$posicaoSelecionada] ?? null)) ?></strong>
                           de terminar em <?= $posicaoSelecionada ?>º lugar</p>
                    </div>
                    <div class="positions-grid">
                        <?php for ($posicao = 1; $posicao <= 20; $posicao++): ?>
                            <?php $valor = $probabilidadesPorPosicao[(string) $posicao]
                                ?? $probabilidadesPorPosicao[$posicao]
                                ?? null; ?>
                            <a class="position-probability" href="?clube_id=<?= (int) $clubeSelecionado['id'] ?>&amp;posicao=<?= $posicao ?>"
                               <?= $posicao === $posicaoSelecionada ? 'aria-current="true"' : '' ?>>
                                <span><?= $posicao ?>º</span>
                                <strong><?= $escapar($formatarProbabilidade($valor)) ?></strong>
                                <?php if ($valor !== null): ?>
                                    <meter min="0" max="100" value="<?= (float) $valor ?>"
                                           aria-label="Chance de terminar em <?= $posicao ?>º"></meter>
                                <?php endif; ?>
                            </a>
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
