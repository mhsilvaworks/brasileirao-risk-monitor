<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Contrato para o motor de simulação Monte Carlo (Issue #11).
 * Permite desacoplar o ProbabilidadeService para testes determinísticos.
 */
interface MonteCarloSimulatorInterface
{
    /**
     * Executa simulações da temporada e retorna as classificações finais de cada iteração.
     *
     * Formato esperado de cada iteração válida:
     * - Lista de entradas contendo 'clube_id' e 'posicao' (1..20), OU
     * - Mapa [clube_id => posicao].
     *
     * @param array<int, array<string, mixed>> $standingsOrClubes
     * @param array<int, array<string, mixed>> $remainingMatches
     * @param int $iterations
     * @return array<int, array<int|string, mixed>>
     */
    public function simulate(
        array $standingsOrClubes,
        array $remainingMatches,
        int $iterations = 2000
    ): array;
}