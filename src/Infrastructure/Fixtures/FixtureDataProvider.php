<?php

declare(strict_types=1);

namespace App\Infrastructure\Fixtures;

use App\Contracts\DataProviderInterface;
use RuntimeException;

final class FixtureDataProvider implements DataProviderInterface
{
    /**
     * Os nomes refletem os snapshots versionados em docs/api-recognition.
     * O diretório pode ser substituído por uma pasta de fixtures de teste.
     */
    private const FIXTURE_FILES = [
        'competition' => 'competition.json',
        'teams' => 'team.json',
        'matches' => 'matches.json',
        'standings' => 'standings.json',
    ];

    public function __construct(private readonly string $fixturesDirectory)
    {
    }

    public function getCompetition(string $competitionCode = 'BSA'): array
    {
        return $this->readFixture('competition');
    }

    public function getTeams(string $competitionCode, int $seasonYear): array
    {
        return $this->readFixture('teams');
    }

    public function getMatches(string $competitionCode, int $seasonYear): array
    {
        return $this->readFixture('matches');
    }

    public function getStandings(string $competitionCode, int $seasonYear): array
    {
        return $this->readFixture('standings');
    }

    /** @return array<string, mixed> */
    private function readFixture(string $name): array
    {
        $fileName = self::FIXTURE_FILES[$name];
        $path = rtrim($this->fixturesDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $fileName;

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Fixture não encontrada ou sem permissão de leitura: ' . $fileName);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Não foi possível ler a fixture: ' . $fileName);
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Fixture contém JSON inválido: ' . $fileName, 0, $exception);
        }

        if (!is_array($payload)) {
            throw new RuntimeException('A fixture deve conter um objeto JSON: ' . $fileName);
        }

        return $payload;
    }
}
