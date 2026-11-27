<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MonteCarloSimulatorInterface;
use InvalidArgumentException;
use Random\Engine;
use Random\Randomizer;

final class MonteCarloSimulator implements MonteCarloSimulatorInterface
{
    private const CLUB_COUNT = 20;
    private const FUTURE_STATUSES = ['SCHEDULED', 'TIMED'];
    private const FINAL_STATUSES = ['FINISHED', 'AWARDED'];

    private readonly Randomizer $random;

    /**
     * Recebe os resultados já sincronizados da mesma temporada. O histórico
     * preserva os placares e o confronto direto que a tabela agregada não contém.
     *
     * @param array<int, array<string, mixed>> $historicalMatches
     */
    public function __construct(
        private readonly StandingsService $standingsService,
        private readonly array $historicalMatches,
        ?Engine $randomEngine = null
    ) {
        $this->random = new Randomizer($randomEngine);
    }

    public function simulate(array $standingsOrClubes, array $remainingMatches, int $iterations = 2000): array
    {
        if ($iterations < 1) {
            throw new InvalidArgumentException('O número de iterações deve ser positivo.');
        }

        $clubs = [];
        foreach ($standingsOrClubes as $row) {
            $id = self::integer($row['clube_id'] ?? $row['id'] ?? null, 1);
            if ($id === false || isset($clubs[$id])) {
                return [];
            }
            $clubs[$id] = ['id' => $id] + $row;
        }
        if (count($clubs) !== self::CLUB_COUNT) {
            return [];
        }

        $futureMatches = array_filter($remainingMatches, static fn (array $match): bool =>
            in_array($match['status'] ?? null, self::FUTURE_STATUSES, true)
        );

        if ($futureMatches === []) {
            foreach ($remainingMatches as $match) {
                if (!in_array($match['status'] ?? null, ['CANCELLED', ...self::FINAL_STATUSES], true)) {
                    return [];
                }
            }
        }

        if ($futureMatches === [] && isset(reset($clubs)['posicao'])) {
            $positions = [];
            foreach ($clubs as $id => $club) {
                $positions[$id] = self::integer($club['posicao'] ?? null, 1);
            }
            $ordered = array_values($positions);
            sort($ordered);
            return $ordered === range(1, self::CLUB_COUNT) ? array_fill(0, $iterations, $positions) : [];
        }

        $historicalMatches = [];
        $homeStats = [];
        $awayStats = [];
        foreach ($this->historicalMatches as $match) {
            if (!in_array($match['status'] ?? null, self::FINAL_STATUSES, true)) {
                continue;
            }
            $teams = self::matchTeams($match, $clubs);
            $homeGoals = self::integer($match['gols_mandante'] ?? null, 0);
            $awayGoals = self::integer($match['gols_visitante'] ?? null, 0);
            if ($teams === null || $homeGoals === false || $awayGoals === false) {
                return [];
            }

            [$home, $away] = $teams;
            $historicalMatches[] = $match;
            self::recordScore($homeStats, $home, $homeGoals, $awayGoals);
            self::recordScore($awayStats, $away, $awayGoals, $homeGoals);
        }

        if ($futureMatches === []) {
            return array_fill(0, $iterations, $this->rank($clubs, $historicalMatches));
        }

        foreach ($clubs as $id => $club) {
            $games = ($homeStats[$id]['games'] ?? 0) + ($awayStats[$id]['games'] ?? 0);
            if (array_key_exists('jogos', $club) && self::integer($club['jogos'], 0) !== $games) {
                return [];
            }
        }

        // As médias ficam fixas durante as simulações e usam somente placares reais.
        $fixtures = [];
        foreach ($futureMatches as $match) {
            $teams = self::matchTeams($match, $clubs);
            if ($teams === null) {
                return [];
            }
            [$home, $away] = $teams;
            if (!isset($homeStats[$home], $awayStats[$away])) {
                return [];
            }
            $homeRecord = $homeStats[$home];
            $awayRecord = $awayStats[$away];
            $fixtures[] = [
                $home,
                $away,
                ($homeRecord['scored'] / $homeRecord['games'] + $awayRecord['conceded'] / $awayRecord['games']) / 2,
                ($awayRecord['scored'] / $awayRecord['games'] + $homeRecord['conceded'] / $homeRecord['games']) / 2,
            ];
        }

        $results = [];
        for ($i = 0; $i < $iterations; $i++) {
            $matches = $historicalMatches;
            foreach ($fixtures as [$home, $away, $homeRate, $awayRate]) {
                $matches[] = [
                    'mandante_id' => $home,
                    'visitante_id' => $away,
                    'status' => 'FINISHED',
                    'gols_mandante' => $this->samplePoisson($homeRate),
                    'gols_visitante' => $this->samplePoisson($awayRate),
                ];
            }
            $results[] = $this->rank($clubs, $matches);
        }

        return $results;
    }

    private function rank(array $clubs, array $matches): array
    {
        $standings = $this->standingsService->calculateFromData($clubs, $matches);
        return array_column($standings, 'posicao', 'clube_id');
    }

    private static function integer(mixed $value, int $minimum): int|false
    {
        if (!is_int($value) && !is_string($value)) {
            return false;
        }
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
    }

    private static function matchTeams(array $match, array $clubs): ?array
    {
        $home = self::integer($match['mandante_id'] ?? null, 1);
        $away = self::integer($match['visitante_id'] ?? null, 1);
        if ($home === false || $away === false || $home === $away || !isset($clubs[$home], $clubs[$away])) {
            return null;
        }
        return [$home, $away];
    }

    private static function recordScore(array &$stats, int $club, int $scored, int $conceded): void
    {
        $stats[$club] ??= ['games' => 0, 'scored' => 0, 'conceded' => 0];
        $stats[$club]['games']++;
        $stats[$club]['scored'] += $scored;
        $stats[$club]['conceded'] += $conceded;
    }

    private function samplePoisson(float $rate): int
    {
        // Soma de interchegadas exponenciais de um processo de Poisson.
        $goals = 0;
        $elapsed = -log(1.0 - $this->random->nextFloat());
        while ($elapsed < $rate) {
            $goals++;
            $elapsed -= log(1.0 - $this->random->nextFloat());
        }
        return $goals;
    }
}
