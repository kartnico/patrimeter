<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Infrastructure\Supervision\Heartbeats;
use Patrimeter\Infrastructure\Supervision\LatestVersionCheck;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

/** /sante and /sante/metriques: without session, without user data. */
final class SupervisionTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->removeMarkers();
    }

    /**
     * The marker files live outside the database: removed after each test too, otherwise the next run of another test
     * (MaintenanceTest, without emptying var/cache/test) would read "99.0.0" left here.
     */
    #[\Override]
    protected function tearDown(): void
    {
        $this->removeMarkers();
        parent::tearDown();
    }

    private function removeMarkers(): void
    {
        $directory = static::getContainer()->getParameter('app.supervision_dir');
        foreach ([Heartbeats::BACKUP, Heartbeats::MAINTENANCE, LatestVersionCheck::AVAILABLE_FILE] as $name) {
            if (is_file("$directory/$name")) {
                unlink("$directory/$name");
            }
        }
    }

    public function testHealthWarnsUntilBackgroundTasksHaveRun(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/sante');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/health+json');
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
        $health = $this->health();
        self::assertSame('warn', $health['status']);
        self::assertSame(['database:responseTime', 'backup:age', 'scheduler:age', 'disk:free', 'update:available'], array_keys($health['checks']));
        self::assertSame('pass', $health['checks']['database:responseTime']['status']);
        self::assertSame('warn', $health['checks']['backup:age']['status']);
        self::assertNull($health['checks']['backup:age']['observedValue']);
    }

    public function testHealthPassesAndMetricsReportTheLastRuns(): void
    {
        $heartbeats = self::service(Heartbeats::class);
        $heartbeats->beat(Heartbeats::BACKUP);
        $heartbeats->beat(Heartbeats::MAINTENANCE);
        $now = self::clock()->now()->getTimestamp();

        $this->client->request('GET', '/sante');
        $health = $this->health();
        self::assertSame('pass', $health['status']);
        self::assertSame(0, $health['checks']['backup:age']['observedValue']);

        $this->client->request('GET', '/sante/metriques');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
        $metrics = $this->body();
        self::assertStringContainsString("# TYPE patrimeter_up gauge\npatrimeter_up 1\n", $metrics);
        self::assertStringContainsString("patrimeter_database_up 1\n", $metrics);
        self::assertStringContainsString("patrimeter_last_backup_timestamp_seconds $now\n", $metrics);
        self::assertStringContainsString("patrimeter_last_maintenance_timestamp_seconds $now\n", $metrics);
        self::assertMatchesRegularExpression('/^patrimeter_disk_free_bytes \d+$/m', $metrics);
        self::assertStringContainsString("patrimeter_update_available 0\n", $metrics);
    }

    public function testANewerVersionFoundByTheDailyCheckOnlyWarns(): void
    {
        self::service(Heartbeats::class)->beat(Heartbeats::BACKUP);
        self::service(Heartbeats::class)->beat(Heartbeats::MAINTENANCE);
        $directory = static::getContainer()->getParameter('app.supervision_dir');
        file_put_contents($directory . '/' . LatestVersionCheck::AVAILABLE_FILE, "99.0.0\n");

        $this->client->request('GET', '/sante');
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $health = $this->health();
        self::assertSame('warn', $health['status']);
        self::assertSame(['status' => 'warn', 'observedValue' => '99.0.0'], $health['checks']['update:available']);

        $this->client->request('GET', '/sante/metriques');
        self::assertStringContainsString("patrimeter_update_available 1\n", $this->body());
        self::assertStringContainsString("patrimeter_up 1\n", $this->body());
    }

    public function testStaleBackupOnlyWarns(): void
    {
        self::service(Heartbeats::class)->beat(Heartbeats::BACKUP);
        self::service(Heartbeats::class)->beat(Heartbeats::MAINTENANCE);
        self::clock()->sleep(27 * 3600);

        $this->client->request('GET', '/sante');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $health = $this->health();
        self::assertSame('warn', $health['status']);
        self::assertSame('warn', $health['checks']['backup:age']['status']);
    }

    /**
     * Answer of /sante, each check reduced to its single measurement.
     *
     * @return array{status: mixed, checks: array<array-key, array<mixed>>}
     */
    private function health(): array
    {
        $health = json_decode($this->body(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($health);
        self::assertIsArray($health['checks']);
        $checks = [];
        foreach ($health['checks'] as $name => $measures) {
            self::assertIsArray($measures);
            self::assertCount(1, $measures);
            self::assertIsArray($measures[0]);
            $checks[$name] = $measures[0];
        }

        return ['status' => $health['status'], 'checks' => $checks];
    }
}
