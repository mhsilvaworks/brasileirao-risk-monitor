<?php

declare(strict_types=1);

use App\Database;
use App\Infrastructure\FootballData\ApiDataProvider;
use App\Repositories\ClubeTemporadaRepository;
use App\Services\Logger;
use App\Services\MonteCarloSimulator;
use App\Services\ProbabilidadeService;
use App\Services\StandingsService;
use App\Services\SyncService;

require dirname(__DIR__) . '/vendor/autoload.php';

$year = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : null;
if ($year === false || ($year !== null && $year < 1) || $argc > 2) {
    fwrite(STDERR, "Uso: php scripts/sync-and-calculate.php [ano]\n");
    exit(2);
}

$pdo = null;
$phase = 'sincronização';
try {
    $pdo = (new Database())->getConnection();
    $provider = new ApiDataProvider((string) getenv('FOOTBALL_DATA_TOKEN'));
    $sync = (new SyncService($pdo, $provider))->syncSeason('BSA', $year);

    $phase = 'cálculo das probabilidades';
    $seasonId = (int) $sync['temporada_id'];
    $pdo->beginTransaction();

    $history = $pdo->prepare(
        "SELECT mandante_id, visitante_id, gols_mandante, gols_visitante, status
           FROM partidas
          WHERE temporada_id = :temporada_id AND status IN ('FINISHED', 'AWARDED')
          ORDER BY id"
    );
    $history->execute([':temporada_id' => $seasonId]);

    $standings = new StandingsService($pdo);
    $service = new ProbabilidadeService(
        new MonteCarloSimulator($standings, $history->fetchAll(PDO::FETCH_ASSOC)),
        $standings,
        new ClubeTemporadaRepository($pdo),
        $pdo
    );

    $results = $service->calculateSeason($seasonId);
    $pdo->commit();

    $available = count(array_filter($results, static fn (array $club): bool =>
        ($club['probabilidades_calculadas_em'] ?? null) !== null
    ));
    if ($available === 0) {
        fwrite(STDERR, "Temporada sincronizada. Probabilidades indisponíveis com os dados atuais.\n");
        exit(1);
    }

    printf("Probabilidades gravadas para %d clubes da temporada %d.\n", $available, $sync['season_year']);
} catch (Throwable $error) {
    if ($pdo?->inTransaction()) {
        $pdo->rollBack();
    }
    (new Logger())->error("Falha na etapa de {$phase}.", ['exception' => $error::class]);
    exit(1);
}
