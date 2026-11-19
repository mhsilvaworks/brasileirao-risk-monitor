<?php

declare(strict_types=1);

namespace App\Infrastructure\FootballData;

use App\Contracts\DataProviderInterface;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

final class ApiDataProvider implements DataProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://api.football-data.org/v4';

    public function __construct(
        private readonly string $apiToken,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeoutSeconds = 10
    ) {
        if (trim($this->apiToken) === '') {
            throw new InvalidArgumentException('O token da football-data.org não foi configurado.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new InvalidArgumentException('O timeout deve ser maior que zero.');
        }
    }

    public function getCompetition(string $competitionCode = 'BSA'): array
    {
        return $this->request('/competitions/' . $this->validateCompetitionCode($competitionCode));
    }

    public function getTeams(string $competitionCode, int $seasonYear): array
    {
        return $this->request(
            '/competitions/' . $this->validateCompetitionCode($competitionCode) . '/teams',
            ['season' => $this->validateSeasonYear($seasonYear)]
        );
    }

    public function getMatches(string $competitionCode, int $seasonYear): array
    {
        return $this->request(
            '/competitions/' . $this->validateCompetitionCode($competitionCode) . '/matches',
            ['season' => $this->validateSeasonYear($seasonYear)]
        );
    }

    public function getStandings(string $competitionCode, int $seasonYear): array
    {
        return $this->request(
            '/competitions/' . $this->validateCompetitionCode($competitionCode) . '/standings',
            ['season' => $this->validateSeasonYear($seasonYear)]
        );
    }

    /**
     * @param array<string, int|string> $query
     * @return array<string, mixed>
     */
    private function request(string $path, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", [
                    'X-Auth-Token: ' . $this->apiToken,
                    'Accept: application/json',
                    'User-Agent: BrasileiraoRiskMonitor/1.0',
                ]),
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = $this->extractStatusCode($responseHeaders);

        if ($status < 200 || $status >= 300) {
            throw new ApiRequestException(
                $status === 0
                    ? 'Não foi possível conectar à football-data.org.'
                    : 'A football-data.org retornou uma resposta HTTP não bem-sucedida.',
                $status === 0 ? null : $status,
                $this->extractHeader($responseHeaders, 'X-RequestCounter-Reset')
            );
        }

        if ($body === false) {
            throw new ApiRequestException('A resposta da football-data.org não pôde ser lida.');
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                'A football-data.org retornou um JSON inválido.',
                0,
                $exception
            );
        }

        if (!is_array($payload)) {
            throw new UnexpectedValueException('O payload da football-data.org não é um objeto JSON válido.');
        }

        return $payload;
    }

    /** @param list<string> $headers */
    private function extractStatusCode(array $headers): int
    {
        $status = 0;

        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }

    /** @param list<string> $headers */
    private function extractHeader(array $headers, string $name): ?string
    {
        foreach (array_reverse($headers) as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    private function validateCompetitionCode(string $competitionCode): string
    {
        $code = strtoupper(trim($competitionCode));

        if (preg_match('/^[A-Z0-9_-]+$/', $code) !== 1) {
            throw new InvalidArgumentException('Código de competição inválido.');
        }

        return $code;
    }

    private function validateSeasonYear(int $seasonYear): int
    {
        if ($seasonYear < 1900 || $seasonYear > 2200) {
            throw new InvalidArgumentException('Ano de temporada inválido.');
        }

        return $seasonYear;
    }
}
