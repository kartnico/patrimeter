<?php

declare(strict_types=1);

use Patrimeter\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

// New test database on each run, created by the migrations (as in production).
// Each test then runs in a transaction that is rolled back (dama/doctrine-test-bundle).
$database = dirname(__DIR__) . '/var/test.sqlite';
foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

$kernel = new Kernel('test', false);
$application = new Application($kernel);
$application->setAutoExit(false);
$output = new BufferedOutput();
$exitCode = $application->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]), $output);
$kernel->shutdown();
if ($exitCode !== 0) {
    throw new RuntimeException("Échec des migrations de la base de test :\n" . $output->fetch());
}
