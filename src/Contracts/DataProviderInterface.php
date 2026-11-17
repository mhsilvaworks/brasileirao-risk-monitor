<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Contrato comum para fontes reais e fixtures da football-data.org.
 *
 * Os métodos retornam o payload JSON decodificado como array associativo.
 * A implementação não deve persistir dados: essa responsabilidade pertence
 * ao SyncService e à camada de persistência.
 */
interface DataProviderInterface
{
    /** @return array<string, mixed> */
    public function getCompetition(string $competitionCode = 'BSA'): array;

    /** @return array<string, mixed> */
    public function getTeams(string $competitionCode, int $seasonYear): array;

    /** @return array<string, mixed> */
    public function getMatches(string $competitionCode, int $seasonYear): array;

    /** @return array<string, mixed> */
    public function getStandings(string $competitionCode, int $seasonYear): array;
}
