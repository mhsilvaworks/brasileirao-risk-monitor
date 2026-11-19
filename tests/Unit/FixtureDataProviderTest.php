<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Fixtures\FixtureDataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FixtureDataProviderTest extends TestCase
{
    private string $fixturesDirectory;

    protected function setUp(): void
    {
        $this->fixturesDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'brm-fixtures-' . bin2hex(random_bytes(5));

        mkdir($this->fixturesDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixturesDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->fixturesDirectory)) {
            rmdir($this->fixturesDirectory);
        }
    }

    public function testReadsCompetitionFixtureAsAssociativeArray(): void
    {
        file_put_contents(
            $this->fixturesDirectory . DIRECTORY_SEPARATOR . 'competition.json',
            '{"id":2013,"code":"BSA"}'
        );

        $provider = new FixtureDataProvider($this->fixturesDirectory);

        self::assertSame(
            ['id' => 2013, 'code' => 'BSA'],
            $provider->getCompetition()
        );
    }

    public function testReadsTeamSnapshotUsingDocumentedFilename(): void
    {
        file_put_contents(
            $this->fixturesDirectory . DIRECTORY_SEPARATOR . 'team.json',
            '{"teams":[{"id":1765,"name":"Fluminense FC"}]}'
        );

        $provider = new FixtureDataProvider($this->fixturesDirectory);

        self::assertSame(
            1765,
            $provider->getTeams('BSA', 2026)['teams'][0]['id']
        );
    }

    public function testRejectsInvalidJsonFixture(): void
    {
        file_put_contents(
            $this->fixturesDirectory . DIRECTORY_SEPARATOR . 'competition.json',
            '{invalid-json'
        );

        $provider = new FixtureDataProvider($this->fixturesDirectory);

        $this->expectException(RuntimeException::class);
        $provider->getCompetition();
    }

    public function testReportsMissingFixture(): void
    {
        $provider = new FixtureDataProvider($this->fixturesDirectory);

        $this->expectException(RuntimeException::class);
        $provider->getMatches('BSA', 2026);
    }
}
