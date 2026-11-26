<?php

declare(strict_types=1);

/*
 * Style: PER Coding Style 3.0, plus the migration rules up to PHP 8.3 only (never beyond: the code must stay
 * compatible with PHP 8.3).
 */
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/bin', __DIR__ . '/config', __DIR__ . '/migrations', __DIR__ . '/public', __DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
    ->append([__DIR__ . '/bin/console', __FILE__])
    ->notPath(['reference.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/cache/php-cs-fixer.cache')
    ->setRules([
        '@PER-CS3x0' => true,
        '@PER-CS3x0:risky' => true,
        '@PHP8x3Migration' => true,
        '@PHP8x3Migration:risky' => true,
        '@PHPUnit11x0Migration:risky' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'strict_comparison' => true,
        'strict_param' => true,
    ])
    ->setFinder($finder);
