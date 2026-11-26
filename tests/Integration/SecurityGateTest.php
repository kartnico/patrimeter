<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Security gate of the pipeline (tools/security-gate.php), on reports of each tool. */
final class SecurityGateTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/security-gate-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = glob("$this->directory/*");
        array_map(unlink(...), $files === false ? [] : $files);
        rmdir($this->directory);
    }

    /** @return iterable<string, array{string, array<mixed>, string}> */
    public static function blockingReports(): iterable
    {
        yield 'npm audit' => ['npm-audit', ['vulnerabilities' => ['paquet' => ['name' => 'paquet', 'severity' => 'high']]], 'paquet'];
        yield 'Trivy, vulnérabilité' => ['trivy', ['Results' => [['Target' => 'composer.lock', 'Vulnerabilities' => [['VulnerabilityID' => 'CVE-1', 'PkgName' => 'a/b', 'Severity' => 'CRITICAL']]]]], 'CVE-1'];
        yield 'Trivy, licence' => ['trivy', ['Results' => [['Target' => 'composer.lock', 'Licenses' => [['Name' => 'AGPL-3.0', 'PkgName' => 'a/b', 'Severity' => 'HIGH']]]]], 'license:AGPL-3.0'];
        yield 'Semgrep' => ['semgrep', ['results' => [['check_id' => 'regle.sql', 'path' => 'src/A.php', 'extra' => ['severity' => 'ERROR', 'message' => 'Injection']]]], 'regle.sql'];
        yield 'ZAP' => ['zap', ['site' => [['@name' => 'http://localhost', 'alerts' => [['pluginid' => '40012', 'name' => 'XSS', 'riskcode' => '3']]]]], '40012'];
        yield 'GitLab' => ['gitlab', ['vulnerabilities' => [['severity' => 'Critical', 'name' => 'Secret', 'identifiers' => [['value' => 'regle-secret']], 'location' => ['file' => 'a.php']]]], 'regle-secret'];
    }

    /** @param array<mixed> $report */
    #[DataProvider('blockingReports')]
    public function testHighOrCriticalFindingBlocks(string $tool, array $report, string $id): void
    {
        $result = $this->gate($tool, $report);

        self::assertSame(1, $result['code'], $result['output']);
        self::assertStringContainsString($id, $result['output']);
        self::assertStringContainsString('bloqué', $result['output']);
    }

    public function testMediumAndLowFindingsDoNotBlock(): void
    {
        $result = $this->gate('semgrep', ['results' => [
            ['check_id' => 'moyen', 'path' => 'a', 'extra' => ['severity' => 'WARNING', 'message' => 'm']],
            ['check_id' => 'faible', 'path' => 'a', 'extra' => ['severity' => 'INFO', 'message' => 'm']],
        ]]);

        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString('| 0 | 0 | 1 | 1 | 0 |', $result['output']);
    }

    public function testJustifiedExclusionLiftsTheBlock(): void
    {
        $report = ['results' => [['check_id' => 'regle', 'path' => 'src/A.php', 'extra' => ['severity' => 'ERROR', 'message' => 'm']]]];

        $result = $this->gate('semgrep', $report, [['tool' => 'semgrep', 'id' => 'regle', 'path' => 'src/A.php', 'reason' => 'Faux positif vérifié.', 'expires' => '2999-01-01']]);

        self::assertSame(0, $result['code'], $result['output']);
        self::assertStringContainsString('| 0 | 0 | 0 | 0 | 1 |', $result['output']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidExclusions(): iterable
    {
        yield 'expirée' => [['tool' => 'semgrep', 'id' => 'regle', 'reason' => 'Motif.', 'expires' => '2020-01-01']];
        yield 'sans motif' => [['tool' => 'semgrep', 'id' => 'regle', 'expires' => '2999-01-01']];
        yield 'sans date' => [['tool' => 'semgrep', 'id' => 'regle', 'reason' => 'Motif.']];
        yield 'autre fichier' => [['tool' => 'semgrep', 'id' => 'regle', 'path' => 'src/B.php', 'reason' => 'Motif.', 'expires' => '2999-01-01']];
        yield 'autre outil' => [['tool' => 'zap', 'id' => 'regle', 'reason' => 'Motif.', 'expires' => '2999-01-01']];
    }

    /** @param array<string, string> $exclusion */
    #[DataProvider('invalidExclusions')]
    public function testInvalidExclusionDoesNotApply(array $exclusion): void
    {
        $report = ['results' => [['check_id' => 'regle', 'path' => 'src/A.php', 'extra' => ['severity' => 'ERROR', 'message' => 'm']]]];

        self::assertSame(1, $this->gate('semgrep', $report, [$exclusion])['code']);
    }

    /** Nuclei writes JSON Lines, one finding per line, and an empty file when it finds nothing. */
    public function testNucleiReportInJsonLines(): void
    {
        $line = static fn(string $id, string $severity): string => json_encode(['template-id' => $id, 'matched-at' => 'http://localhost:8765/', 'info' => ['name' => $id, 'severity' => $severity]], JSON_THROW_ON_ERROR);
        file_put_contents("$this->directory/vide.jsonl", '');
        file_put_contents("$this->directory/faible.jsonl", $line('en-tete', 'low') . "\n" . $line('techno', 'info') . "\n");
        file_put_contents("$this->directory/grave.jsonl", $line('cve-2026-1', 'critical') . "\n");
        file_put_contents("$this->directory/illisible.jsonl", "pas du JSON\n");

        $empty = $this->runGate("nuclei=$this->directory/vide.jsonl");
        self::assertSame(0, $empty['code'], $empty['output']);
        $low = $this->runGate("nuclei=$this->directory/faible.jsonl");
        self::assertSame(0, $low['code'], $low['output']);
        self::assertStringContainsString('| 0 | 0 | 0 | 1 | 0 |', $low['output']);
        $critical = $this->runGate("nuclei=$this->directory/grave.jsonl");
        self::assertSame(1, $critical['code']);
        self::assertStringContainsString('cve-2026-1', $critical['output']);
        self::assertSame(1, $this->runGate("nuclei=$this->directory/illisible.jsonl")['code']);
        self::assertSame(1, $this->runGate("nuclei=$this->directory/absent.jsonl")['code']);
    }

    public function testMissingOrUnreadableReportBlocks(): void
    {
        file_put_contents("$this->directory/illisible.json", 'pas du JSON');
        file_put_contents("$this->directory/vide.json", '{}');

        self::assertSame(1, $this->runGate('semgrep=' . $this->directory . '/absent.json')['code']);
        self::assertSame(1, $this->runGate('trivy=' . $this->directory . '/illisible.json')['code']);
        self::assertSame(1, $this->runGate('zap=' . $this->directory . '/vide.json')['code']);
    }

    /**
     * @param array<mixed>       $report
     * @param list<array<mixed>> $exclusions
     *
     * @return array{code: int, output: string}
     */
    private function gate(string $tool, array $report, array $exclusions = []): array
    {
        file_put_contents("$this->directory/report.json", json_encode($report, JSON_THROW_ON_ERROR));

        return $this->runGate("$tool=$this->directory/report.json", $exclusions);
    }

    /**
     * @param list<array<mixed>> $exclusions
     *
     * @return array{code: int, output: string}
     */
    private function runGate(string $report, array $exclusions = []): array
    {
        file_put_contents("$this->directory/exclusions.json", json_encode(['exclusions' => $exclusions], JSON_THROW_ON_ERROR));
        $command = implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, dirname(__DIR__, 2) . '/tools/security-gate.php', "$this->directory/exclusions.json", "$this->directory/summary.md", $report]));
        $output = [];
        $code = 0;
        exec("$command 2>&1", $output, $code);

        return ['code' => $code, 'output' => implode("\n", $output)];
    }
}
