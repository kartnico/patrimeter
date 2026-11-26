<?php

declare(strict_types=1);

/*
 * Summary of the pipeline: security gate, end-to-end flows, load and cost of Argon2id, from the artifacts present
 * (a missing artifact is reported, without failing).
 *
 * Usage: php tools/pipeline-report.php <reports folder> <output.md>
 */

$directory = rtrim($argv[1] ?? 'reports', '/');
$output = $argv[2] ?? "$directory/pipeline-report.md";

/** @return array<mixed>|null */
function json(string $path): ?array
{
    $content = @file_get_contents($path);
    $data = $content === false ? null : json_decode($content, true);

    return is_array($data) ? $data : null;
}

/** @param array<mixed> $values */
function metric(array $values, string $name, string $key): string
{
    $metrics = $values['metrics'] ?? null;
    $metric = is_array($metrics) ? ($metrics[$name] ?? null) : null;
    $value = is_array($metric) ? ($metric[$key] ?? null) : null;

    return is_numeric($value) ? (string) round((float) $value, 3) : 'n/d';
}

$lines = ['# Rapport du pipeline Patrimeter', ''];
$commit = getenv('CI_COMMIT_SHORT_SHA');
if (is_string($commit) && $commit !== '') {
    $lines[] = "Commit `$commit`, pipeline " . (string) getenv('CI_PIPELINE_ID') . '.';
    $lines[] = '';
}

$security = @file_get_contents("$directory/security-summary.md");
$lines[] = $security === false ? "## Barrière de sécurité\n\nSynthèse absente." : trim($security);
$lines[] = '';

$lines[] = '## Parcours de bout en bout';
$lines[] = '';
$junit = @simplexml_load_file("$directory/e2e-junit.xml");
if ($junit === false) {
    $lines[] = 'Résultats absents.';
} else {
    $lines[] = sprintf('%d parcours, %d échec(s), %d ignoré(s).', (int) $junit['tests'], (int) $junit['failures'], (int) $junit['skipped']);
}
$lines[] = '';

$lines[] = '## Charge (k6)';
$lines[] = '';
$k6 = json("$directory/k6-summary.json");
if ($k6 === null) {
    $lines[] = 'Résultats absents.';
} else {
    $lines[] = '| Mesure | Valeur | Seuil |';
    $lines[] = '|---|---|---|';
    $lines[] = sprintf('| Pages, p95 | %s ms | 800 ms |', metric($k6, 'http_req_duration{kind:page}', 'p(95)'));
    $lines[] = sprintf('| Saisie, p95 | %s ms | 1 000 ms |', metric($k6, 'http_req_duration{kind:entry}', 'p(95)'));
    $lines[] = sprintf('| Connexion, p95 | %s ms | 2 000 ms |', metric($k6, 'http_req_duration{kind:login}', 'p(95)'));
    $lines[] = sprintf('| Requêtes en erreur | %s | 1 %% |', metric($k6, 'http_req_failed', 'value'));
}
$lines[] = '';

$argon = json("$directory/argon-cost.json");
if ($argon !== null) {
    $lines[] = sprintf('Coût d\'Argon2id (64 Mio, 3 passes) : %s ms en médiane.', is_numeric($argon['median_ms'] ?? null) ? (string) $argon['median_ms'] : 'n/d');
    $lines[] = '';
}

file_put_contents($output, implode("\n", $lines));
echo implode("\n", $lines);
