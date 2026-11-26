<?php

declare(strict_types=1);

/*
 * Security gate of the pipeline: gathers the reports of the tools, blocks on any High or Critical vulnerability
 * not covered by a justified and dated exclusion (security/exclusions.json). A missing or unreadable report
 * blocks too: a tool that broke down is not a happy tool.
 *
 * Usage: php tools/security-gate.php <exclusions.json> <summary.md> <tool>=<report.json>…
 * Tools: npm-audit, trivy, semgrep, zap, gitlab (SAST and Secret Detection).
 */

$blockingLevels = ['high', 'critical'];

/** @return array<mixed> */
function readJson(string $path): array
{
    $content = @file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("rapport absent : $path");
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        throw new RuntimeException("rapport illisible : $path");
    }

    return $data;
}

/**
 * Report in JSON Lines (Nuclei: one finding per line; an empty file when nothing is found).
 *
 * @return array{findings: list<array<mixed>>}
 */
function readJsonLines(string $path): array
{
    $content = @file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("rapport absent : $path");
    }
    $findings = [];
    $lines = preg_split('/\R/', $content);
    foreach ($lines === false ? [] : $lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $finding = json_decode($line, true);
        if (!is_array($finding)) {
            throw new RuntimeException("rapport illisible : $path");
        }
        $findings[] = $finding;
    }

    return ['findings' => $findings];
}

/** @return list<array<mixed>> */
function rows(mixed $list): array
{
    return is_array($list) ? array_values(array_filter($list, is_array(...))) : [];
}

function text(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

/** @return array{id: string, path: string, title: string, level: string} */
function finding(mixed $id, mixed $path, mixed $title, string $level): array
{
    return ['id' => text($id), 'path' => text($path), 'title' => text($title), 'level' => strtolower($level)];
}

/**
 * @param array<mixed> $report
 *
 * @return list<array{id: string, path: string, title: string, level: string}>
 */
function npmAudit(array $report): array
{
    $findings = [];
    foreach (rows($report['vulnerabilities'] ?? null) as $vulnerability) {
        $findings[] = finding($vulnerability['name'] ?? '', 'package-lock.json', $vulnerability['name'] ?? '', text($vulnerability['severity'] ?? ''));
    }

    return $findings;
}

/**
 * @param array<mixed> $report
 *
 * @return list<array{id: string, path: string, title: string, level: string}>
 */
function trivy(array $report): array
{
    $findings = [];
    foreach (rows($report['Results'] ?? null) as $result) {
        foreach (rows($result['Vulnerabilities'] ?? null) as $vulnerability) {
            $findings[] = finding($vulnerability['VulnerabilityID'] ?? '', $result['Target'] ?? '', text($vulnerability['PkgName'] ?? '') . ' : ' . text($vulnerability['Title'] ?? ''), text($vulnerability['Severity'] ?? ''));
        }
        foreach (rows($result['Licenses'] ?? null) as $license) {
            $findings[] = finding('license:' . text($license['Name'] ?? ''), $license['PkgName'] ?? '', 'Licence ' . text($license['Name'] ?? '') . ' : ' . text($license['PkgName'] ?? ''), text($license['Severity'] ?? ''));
        }
    }

    return $findings;
}

/**
 * @param array<mixed> $report
 *
 * @return list<array{id: string, path: string, title: string, level: string}>
 */
function semgrep(array $report): array
{
    if (!isset($report['results'])) {
        throw new RuntimeException('rapport Semgrep sans résultats');
    }
    $findings = [];
    foreach (rows($report['results']) as $result) {
        $extra = is_array($result['extra'] ?? null) ? $result['extra'] : [];
        $severity = strtolower(text($extra['severity'] ?? ''));
        $findings[] = finding($result['check_id'] ?? '', $result['path'] ?? '', $extra['message'] ?? '', match ($severity) {
            'error' => 'high',
            'warning' => 'medium',
            'info' => 'low',
            default => $severity,
        });
    }

    return $findings;
}

/**
 * @param array<mixed> $report
 *
 * @return list<array{id: string, path: string, title: string, level: string}>
 */
function nuclei(array $report): array
{
    $findings = [];
    foreach (rows($report['findings'] ?? null) as $result) {
        $info = is_array($result['info'] ?? null) ? $result['info'] : [];
        $findings[] = finding($result['template-id'] ?? '', $result['matched-at'] ?? '', $info['name'] ?? '', text($info['severity'] ?? 'info'));
    }

    return $findings;
}

/**
 * @param array<mixed> $report
 *
 * @return list<array{id: string, path: string, title: string, level: string}>
 */
function zap(array $report): array
{
    if (!isset($report['site'])) {
        throw new RuntimeException('rapport ZAP sans site');
    }
    $levels = ['info', 'low', 'medium', 'high'];
    $findings = [];
    foreach (rows($report['site']) as $site) {
        foreach (rows($site['alerts'] ?? null) as $alert) {
            $findings[] = finding($alert['pluginid'] ?? '', $site['@name'] ?? '', $alert['name'] ?? '', $levels[(int) text($alert['riskcode'] ?? '0')] ?? 'info');
        }
    }

    return $findings;
}

/**
 * @param array<mixed> $report
 *
 * @return list<array{id: string, path: string, title: string, level: string}>
 */
function gitlab(array $report): array
{
    if (!isset($report['vulnerabilities'])) {
        throw new RuntimeException('rapport GitLab sans vulnérabilités');
    }
    $findings = [];
    foreach (rows($report['vulnerabilities']) as $vulnerability) {
        $location = is_array($vulnerability['location'] ?? null) ? $vulnerability['location'] : [];
        $identifiers = rows($vulnerability['identifiers'] ?? null);
        $findings[] = finding($identifiers[0]['value'] ?? ($vulnerability['id'] ?? ''), $location['file'] ?? '', $vulnerability['name'] ?? ($vulnerability['message'] ?? ''), text($vulnerability['severity'] ?? ''));
    }

    return $findings;
}

/**
 * @param array{id: string, path: string, title: string, level: string} $finding
 * @param list<array<mixed>>                                            $exclusions
 *
 * @return array<mixed>|null
 */
function exclusionFor(string $tool, array $finding, array $exclusions, string $today): ?array
{
    foreach ($exclusions as $exclusion) {
        $path = text($exclusion['path'] ?? '');
        if (text($exclusion['tool'] ?? '') === $tool
            && text($exclusion['id'] ?? '') === $finding['id']
            && ($path === '' || $path === $finding['path'])
            && text($exclusion['reason'] ?? '') !== ''
            && text($exclusion['expires'] ?? '') >= $today) {
            return $exclusion;
        }
    }

    return null;
}

$exclusionsFile = $argv[1] ?? '';
$summaryFile = $argv[2] ?? '';
$today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
$exclusions = rows(readJson($exclusionsFile)['exclusions'] ?? null);
$errors = [];
$lines = ['## Barrière de sécurité', '', '| Outil | Critical | High | Medium | Low | Exclus |', '|---|---|---|---|---|---|'];
$blocking = [];

foreach (array_slice($argv, 3) as $argument) {
    [$tool, $path] = explode('=', $argument, 2) + [1 => ''];
    $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
    $excluded = 0;
    try {
        $report = $tool === 'nuclei' ? readJsonLines($path) : readJson($path);
        $findings = match ($tool) {
            'npm-audit' => npmAudit($report),
            'trivy' => trivy($report),
            'semgrep' => semgrep($report),
            'zap' => zap($report),
            'nuclei' => nuclei($report),
            'gitlab' => gitlab($report),
            default => throw new RuntimeException("outil inconnu : $tool"),
        };
    } catch (RuntimeException $e) {
        $errors[] = "$tool : " . $e->getMessage();
        $lines[] = "| $tool | ? | ? | ? | ? | ? |";
        continue;
    }
    foreach ($findings as $finding) {
        if (exclusionFor($tool, $finding, $exclusions, $today) !== null) {
            ++$excluded;
            continue;
        }
        if (isset($counts[$finding['level']])) {
            ++$counts[$finding['level']];
        }
        if (in_array($finding['level'], $blockingLevels, true)) {
            $blocking[] = sprintf('[%s] %s %s (%s) : %s', $finding['level'], $tool, $finding['id'], $finding['path'], $finding['title']);
        }
    }
    $lines[] = sprintf('| %s (%s) | %d | %d | %d | %d | %d |', $tool, basename($path), $counts['critical'], $counts['high'], $counts['medium'], $counts['low'], $excluded);
}

$lines[] = '';
foreach ($errors as $error) {
    $lines[] = "- **Erreur** : $error";
}
foreach ($blocking as $item) {
    $lines[] = "- **Bloquant** : $item";
}
$verdict = $errors === [] && $blocking === [];
$lines[] = $verdict ? '**Verdict : aucun blocage.**' : '**Verdict : bloqué.**';
$summary = implode("\n", $lines) . "\n";
if ($summaryFile !== '') {
    file_put_contents($summaryFile, $summary);
}
echo $summary;
exit($verdict ? 0 : 1);
