<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MonteCarloSimulator;
use App\Services\StandingsService;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;

final class MonteCarloSimulatorTest extends TestCase
{
    public function testDefaultIterationsProduceValidRankings(): void
    {
        $results = $this->simulator()->simulate($this->clubs(), [$this->fixture()]);
        self::assertCount(2000, $results);

        foreach ($results as $ranking) {
            $ids = array_keys($ranking);
            $positions = array_values($ranking);
            sort($ids);
            sort($positions);
            self::assertSame(range(101, 120), $ids);
            self::assertSame(range(1, 20), $positions);
        }
        self::assertGreaterThan(1, count(array_unique(array_column($results, 101))));
    }

    public function testSeedIsReproducibleAndOnlyScheduledGamesAreSimulated(): void
    {
        $fixture = $this->fixture('TIMED');
        $expected = $this->simulator()->simulate($this->clubs(), [$fixture], 25);
        $remaining = [$fixture];
        foreach (['IN_PLAY', 'PAUSED', 'POSTPONED', 'CANCELLED', 'SUSPENDED', 'UNKNOWN'] as $status) {
            $remaining[] = $this->fixture($status);
        }

        self::assertSame($expected, $this->simulator()->simulate($this->clubs(), $remaining, 25));
    }

    public function testWithoutRemainingMatchesPreservesCurrentClassification(): void
    {
        $standings = (new StandingsService())->calculateFromData($this->clubs(), [$this->playedMatch()]);
        $ranking = array_column($standings, 'posicao', 'clube_id');

        $simulator = new MonteCarloSimulator(new StandingsService(), []);
        self::assertSame([$ranking, $ranking], $simulator->simulate($standings, [], 2));
        self::assertSame([], $simulator->simulate($standings, [$this->fixture('IN_PLAY')], 2));
    }

    public function testIncompleteDataProducesNoValidIteration(): void
    {
        $fixture = $this->fixture();
        $played = $this->playedMatch();
        $played['gols_visitante'] = null;

        $missingScore = new MonteCarloSimulator(new StandingsService(), [$played]);
        self::assertSame([], $missingScore->simulate($this->clubs(), [$fixture], 1));

        $fixture['visitante_id'] = 103;
        self::assertSame([], $this->simulator()->simulate($this->clubs(), [$fixture], 1));
        self::assertSame([], $this->simulator()->simulate(array_slice($this->clubs(), 1), [$fixture], 1));
    }

    private function simulator(): MonteCarloSimulator
    {
        return new MonteCarloSimulator(new StandingsService(), [$this->playedMatch()], new Mt19937(42));
    }

    private function clubs(): array
    {
        return array_map(
            static fn (int $id): array => ['id' => $id, 'nome' => "Clube $id"],
            range(101, 120)
        );
    }

    private function fixture(string $status = 'SCHEDULED'): array
    {
        return ['mandante_id' => 101, 'visitante_id' => 102, 'status' => $status];
    }

    private function playedMatch(): array
    {
        return $this->fixture('FINISHED') + ['gols_mandante' => 1, 'gols_visitante' => 1];
    }
}
