<?php

declare(strict_types=1);

/*
 * Checks the line coverage thresholds from a PHPUnit Clover report.
 *
 * Usage: php tools/coverage-check.php var/coverage/clover.xml src=80 src/Domain=95 src/Security=95
 * Each threshold applies to the files whose path starts with the given prefix.
 * Exit code 1 if a threshold is not reached (or if a prefix contains no file).
 */

$arguments = array_slice($argv, 1);
$cloverFile = array_shift($arguments);
if ($cloverFile === null || $arguments === []) {
    fwrite(STDERR, "Usage : php tools/coverage-check.php <clover.xml> <préfixe>=<pourcentage> [...]\n");
    exit(2);
}

$clover = simplexml_load_file($cloverFile);
if ($clover === false) {
    fwrite(STDERR, "Rapport Clover illisible : $cloverFile\n");
    exit(2);
}

$root = dirname(__DIR__) . '/';
$files = [];
foreach ($clover->xpath('//file') ?? [] as $file) {
    $metrics = $file->metrics;
    if ($metrics === null) {
        continue;
    }
    $path = str_replace($root, '', (string) $file['name']);
    $files[$path] = [(int) $metrics['statements'], (int) $metrics['coveredstatements']];
}

$failed = false;
printf("%-20s %9s %8s %8s\n", 'Périmètre', 'Lignes', 'Couvert', 'Seuil');
foreach ($arguments as $rule) {
    [$prefix, $threshold] = explode('=', $rule, 2) + [1 => ''];
    $statements = 0;
    $covered = 0;
    foreach ($files as $path => [$fileStatements, $fileCovered]) {
        if (str_starts_with($path, rtrim($prefix, '/') . '/')) {
            $statements += $fileStatements;
            $covered += $fileCovered;
        }
    }
    $percent = $statements === 0 ? 0.0 : $covered * 100 / $statements;
    $ok = $statements > 0 && $percent >= (float) $threshold;
    $failed = $failed || !$ok;
    printf("%-20s %9d %7.2f%% %7.0f%% %s\n", $prefix, $statements, $percent, (float) $threshold, $ok ? 'OK' : 'INSUFFISANT');
}

exit($failed ? 1 : 0);
