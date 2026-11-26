<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ClubeTemporadaRepositoryInterface;
use App\Contracts\MonteCarloSimulatorInterface;
use App\Services\ProbabilidadeService;
use App\Services\StandingsService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProbabilidadeServiceTest extends TestCase
{
    public function testCalculateZoneProbabilitiesWithDeterministicSimulations(): void
    {
        $service = new ProbabilidadeService();

        // 4 iterações determinísticas com 4 clubes (IDs 10, 20, 30, 40)
        // Clube 10: posições [1, 1, 2, 7] -> campeao 50%, libertadores 75%, sulamericana 25%, rebaixamento 0%
        // Clube 20: posições [2, 6, 7, 17] -> campeao 0%, libertadores 50%, sulamericana 25%, rebaixamento 25%
        // Clube 30: posições [17, 18, 19, 20] -> campeao 0%, libertadores 0%, sulamericana 0%, rebaixamento 100%
        // Clube 40: posições [8, 9, 10, 12] -> campeao 0%, libertadores 0%, sulamericana 100%, rebaixamento 0%
        $simulations = [
            [10 => 1, 20 => 2, 30 => 17, 40 => 8],
            [10 => 1, 20 => 6, 30 => 18, 40 => 9],
            [10 => 2, 20 => 7, 30 => 19, 40 => 10],
            [10 => 7, 20 => 17, 30 => 20, 40 => 12],
        ];

        $zones = $service->calculateZoneProbabilities($simulations);

        $this->assertSame(50.0, $zones[10]['campeao']);
        $this->assertSame(75.0, $zones[10]['libertadores']);
        $this->assertSame(25.0, $zones[10]['sulamericana']);
        $this->assertSame(0.0, $zones[10]['rebaixamento']);

        $this->assertSame(0.0, $zones[20]['campeao']);
        $this->assertSame(50.0, $zones[20]['libertadores']);
        $this->assertSame(25.0, $zones[20]['sulamericana']);
        $this->assertSame(25.0, $zones[20]['rebaixamento']);

        $this->assertSame(0.0, $zones[30]['campeao']);
        $this->assertSame(0.0, $zones[30]['libertadores']);
        $this->assertSame(0.0, $zones[30]['sulamericana']);
        $this->assertSame(100.0, $zones[30]['rebaixamento']);

        $this->assertSame(100.0, $zones[40]['sulamericana']);
    }

    public function testCalculateZoneProbabilitiesSupportsCustomZoneRules(): void
    {
        $customRules = [
            'campeao' => ['min' => 1, 'max' => 1],
            'libertadores' => ['min' => 1, 'max' => 4],
            'sulamericana' => ['min' => 5, 'max' => 10],
            'rebaixamento' => ['min' => 18, 'max' => 20],
        ];

        $service = new ProbabilidadeService(zoneRules: $customRules);

        $simulations = [
            [1 => 4, 2 => 5, 3 => 17],
            [1 => 5, 2 => 10, 3 => 18],
        ];

        $zones = $service->calculateZoneProbabilities($simulations);

        $this->assertSame(50.0, $zones[1]['libertadores']);
        $this->assertSame(50.0, $zones[1]['sulamericana']);
        $this->assertSame(100.0, $zones[2]['sulamericana']);
        $this->assertSame(50.0, $zones[3]['rebaixamento']);
    }

    public function testCalculateZoneProbabilitiesPreservesNullWhenNoValidIterations(): void
    {
        $service = new ProbabilidadeService();

        $this->assertSame([], $service->calculateZoneProbabilities([]));

        $withClubs = $service->calculateZoneProbabilities([[], ['invalid' => 'data']], [1, 2]);
        $this->assertNull($withClubs[1]['campeao']);
        $this->assertNull($withClubs[1]['libertadores']);
        $this->assertNull($withClubs[1]['sulamericana']);
        $this->assertNull($withClubs[1]['rebaixamento']);
        $this->assertNull($withClubs[2]['rebaixamento']);
    }

    public function testBuildPositionDistributionReturnsExact20StringKeysAndSumsTo100(): void
    {
        $service = new ProbabilidadeService();

        // 3 iterações -> testa também arredondamento (33.33 + 33.33 + 33.33 = 99.99, dentro da tolerância)
        $simulations = [
            [
                ['clube_id' => 5, 'posicao' => 1],
                ['clube_id' => 6, 'posicao' => 20],
            ],
            [
                ['clube_id' => 5, 'posicao' => 2],
                ['clube_id' => 6, 'posicao' => 20],
            ],
            [
                ['clube_id' => 5, 'posicao' => 3],
                ['clube_id' => 6, 'posicao' => 20],
            ],
        ];

        $distributions = $service->buildPositionDistribution($simulations);

        $this->assertArrayHasKey(5, $distributions);
        $this->assertNotNull($distributions[5]);
        $this->assertCount(20, $distributions[5]);

        $this->assertSame(range(1, 20), array_keys($distributions[5]));
        $json = json_encode($distributions[5], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"1":33.33', $json);
        $this->assertStringContainsString('"20":0', $json);

        $this->assertSame(33.33, $distributions[5]['1']);
        $this->assertSame(33.33, $distributions[5]['2']);
        $this->assertSame(33.33, $distributions[5]['3']);
        $this->assertSame(0.0, $distributions[5]['4']);
        $this->assertEqualsWithDelta(100.0, array_sum($distributions[5]), ProbabilidadeService::POSITION_SUM_TOLERANCE);

        $this->assertSame(100.0, $distributions[6]['20']);
        $this->assertSame(100.0, array_sum($distributions[6]));
    }

    public function testBuildPositionDistributionReturnsNullOnEmptyOrInvalidInput(): void
    {
        $service = new ProbabilidadeService();

        $this->assertSame([], $service->buildPositionDistribution([]));

        $result = $service->buildPositionDistribution(
            [[['clube_id' => 1, 'posicao' => 25]]], // posição inválida > 20
            [1, 2]
        );

        $this->assertNull($result[1]);
        $this->assertNull($result[2]);
    }

    public function testCalculateSeasonOrchestrates2000IterationsAndPersistsResults(): void
    {
        $capturedIterations = null;
        $persistedRecords = [];

        $simulator = new class ($capturedIterations) implements MonteCarloSimulatorInterface {
            /** @var int|null */
            private $ref;

            public function __construct(?int &$ref)
            {
                $this->ref = &$ref;
            }

            public function simulate(array $standingsOrClubes, array $remainingMatches, int $iterations = 2000): array
            {
                $this->ref = $iterations;
                return [
                    [1 => 1, 2 => 18],
                    [1 => 2, 2 => 17],
                ];
            }
        };

        $repository = new class ($persistedRecords) implements ClubeTemporadaRepositoryInterface {
            /** @var array<int, array<string, mixed>> */
            private $store;

            /** @param array<int, array<string, mixed>> $store */
            public function __construct(array &$store)
            {
                $this->store = &$store;
            }

            public function upsertProbabilities(int $temporadaId, int $clubeId, array $probabilidades): void
            {
                $this->store[$clubeId] = array_merge(
                    ['temporada_id' => $temporadaId, 'clube_id' => $clubeId],
                    $probabilidades
                );
            }

            public function findByTemporadaAndClube(int $temporadaId, int $clubeId): ?array
            {
                return $this->store[$clubeId] ?? null;
            }

            public function findByTemporada(int $temporadaId): array
            {
                return [
                    ['clube_id' => 1, 'nome' => 'Clube A', 'nome_curto' => 'Clube A', 'sigla' => 'CLA', 'ajuste_pontos' => 0],
                    ['clube_id' => 2, 'nome' => 'Clube B', 'nome_curto' => 'Clube B', 'sigla' => 'CLB', 'ajuste_pontos' => 0],
                ];
            }
        };

        $service = new ProbabilidadeService(
            simulator: $simulator,
            standingsService: new StandingsService(),
            clubeTemporadaRepository: $repository
        );

        $results = $service->calculateSeason(2026);

        $this->assertSame(2000, $capturedIterations);
        $this->assertSame(50.0, $results[1]['probabilidade_campeao']);
        $this->assertSame(100.0, $results[1]['probabilidade_libertadores']);
        $this->assertSame(100.0, $results[2]['probabilidade_rebaixamento']);
        $this->assertNotNull($results[1]['probabilidades_calculadas_em']);
        $this->assertCount(20, $results[1]['probabilidades_posicoes']);

        // Verifica persistência e leitura via getClubProbabilities sem reexecutar simulador
        $this->assertArrayHasKey(1, $persistedRecords);
        $club1 = $service->getClubProbabilities(2026, 1);
        $this->assertTrue($club1['disponivel']);
        $this->assertTrue($club1['estimativa']);
        $this->assertSame(50.0, $club1['probabilidade_campeao']);
    }

    public function testCalculateSeasonPreservesNullWhenSimulatorReturnsEmpty(): void
    {
        $persistedRecords = [];

        $emptySimulator = new class implements MonteCarloSimulatorInterface {
            public function simulate(array $standingsOrClubes, array $remainingMatches, int $iterations = 2000): array
            {
                return [];
            }
        };

        $repository = new class ($persistedRecords) implements ClubeTemporadaRepositoryInterface {
            /** @var array<int, array<string, mixed>> */
            private $store;

            /** @param array<int, array<string, mixed>> $store */
            public function __construct(array &$store)
            {
                $this->store = &$store;
            }

            public function upsertProbabilities(int $temporadaId, int $clubeId, array $probabilidades): void
            {
                $this->store[$clubeId] = $probabilidades;
            }

            public function findByTemporadaAndClube(int $temporadaId, int $clubeId): ?array
            {
                return $this->store[$clubeId] ?? null;
            }

            public function findByTemporada(int $temporadaId): array
            {
                return [
                    ['clube_id' => 1, 'nome' => 'Clube A', 'nome_curto' => 'Clube A', 'sigla' => 'CLA', 'ajuste_pontos' => 0],
                ];
            }
        };

        $service = new ProbabilidadeService(
            simulator: $emptySimulator,
            standingsService: new StandingsService(),
            clubeTemporadaRepository: $repository
        );

        $results = $service->calculateSeason(2026);

        $this->assertNull($results[1]['probabilidade_campeao']);
        $this->assertNull($results[1]['probabilidades_posicoes']);
        $this->assertNull($results[1]['probabilidades_calculadas_em']);

        $club1 = $service->getClubProbabilities(2026, 1);
        $this->assertFalse($club1['disponivel']);
        $this->assertNull($club1['probabilidade_campeao']);
    }

    public function testPersistResultsRejectsOutOfRangePercentage(): void
    {
        $repository = $this->createMock(ClubeTemporadaRepositoryInterface::class);
        $repository->expects($this->never())->method('upsertProbabilities');

        $service = new ProbabilidadeService(clubeTemporadaRepository: $repository);

        $this->expectException(InvalidArgumentException::class);

        $service->persistResults(2026, [
            1 => [
                'clube_id' => 1,
                'probabilidade_campeao' => 105.5,
                'probabilidade_libertadores' => 50.0,
                'probabilidade_sulamericana' => 10.0,
                'probabilidade_rebaixamento' => 0.0,
                'probabilidades_posicoes' => null,
                'probabilidades_calculadas_em' => null,
            ],
        ]);
    }
}