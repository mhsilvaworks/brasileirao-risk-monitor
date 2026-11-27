<?php

declare(strict_types=1);

$sincronizacao = $ultimaSincronizacao ?? null;
$consultaSincronizacaoIndisponivel = $consultaSincronizacaoIndisponivel ?? false;

?>
<footer class="app-footer" aria-label="Estado da sincronização">
    <?php if ($consultaSincronizacaoIndisponivel): ?>
        <p role="status">Não foi possível consultar a última sincronização.</p>
    <?php elseif (!is_array($sincronizacao)): ?>
        <p role="status">Nenhuma sincronização bem-sucedida registrada.</p>
    <?php else: ?>
        <?php
        $dataUtc = new DateTimeImmutable(
            (string) $sincronizacao['finalizado_em'],
            new DateTimeZone('UTC')
        );
        $dataLocal = $dataUtc->setTimezone(
            new DateTimeZone('America/Sao_Paulo')
        );
        ?>
        <p>
            Última sincronização concluída com sucesso
            <time datetime="<?= htmlspecialchars(
                $dataUtc->format(DateTimeInterface::ATOM),
                ENT_QUOTES,
                'UTF-8'
            ) ?>">
                <?= htmlspecialchars(
                    $dataLocal->format('d/m/Y H:i'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </time>
        </p>
    <?php endif; ?>
</footer>
