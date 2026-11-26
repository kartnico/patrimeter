<?php

declare(strict_types=1);

/*
 * Test users ready to sign in (known TOTP, recovery key written down), with their data encrypted by the
 * application's real code: the example data of the demonstration mode (Patrimeter\Application\Demo\DemoPortfolio),
 * accounts and statements, and household budget (two adults, one child, amounts saved three months ago then this
 * month).
 *
 * Usage: php tests/e2e/seed.php <users> <years of history> [<English-speaking users>]
 * Addresses: e2e-01@example.test, e2e-02@example.test…; e2e-en-01@example.test… for the users whose accounts have
 * English names (screenshots of the English documentation). With at least two users in a language, a family account
 * (e2e-famille@example.test, e2e-en-famille@example.test) imports their export files, under the first names of the
 * demonstration household.
 */

use Patrimeter\Application\Demo\DemoPortfolio;
use Patrimeter\Application\Family\FamilyPortfolio;
use Patrimeter\Application\Transfer\DataTransfer;
use Patrimeter\Tests\Support\SeedKernel;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$userCount = (int) ($argv[1] ?? 1);
$years = (int) ($argv[2] ?? 1);
$englishCount = (int) ($argv[3] ?? 0);

/** Demonstration household (DemoPortfolio): first names, French then English, by name column. */
const DEMO_HOUSEHOLD = [1 => DemoPortfolio::HOUSEHOLD['fr'], 2 => DemoPortfolio::HOUSEHOLD['en']];

(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$kernel = new SeedKernel('seed', false);
$kernel->boot();
$container = $kernel->getContainer();

/**
 * @template T of object
 *
 * @param class-string<T> $id
 *
 * @return T
 */
function seedService(ContainerInterface $container, string $id): object
{
    $instance = $container->get($id);
    if (!$instance instanceof $id) {
        throw new LogicException("Service absent : $id");
    }

    return $instance;
}

$portfolio = seedService($container, DemoPortfolio::class);

$users = [];
for ($number = 1; $number <= $userCount; ++$number) {
    $users[sprintf('e2e-%02d@example.test', $number)] = 1;
}
for ($number = 1; $number <= $englishCount; ++$number) {
    $users[sprintf('e2e-en-%02d@example.test', $number)] = 2;
}

foreach ($users as $email => $nameColumn) {
    $owner = TestUsers::create($container, $email);
    $portfolio->seed($owner, $nameColumn === 2 ? 'en' : 'fr', $years);
    echo "Utilisateur $email prêt\n";
}

// Family accounts: members imported from the export files of the first two users of each language.
$transfer = seedService($container, DataTransfer::class);
$family = seedService($container, FamilyPortfolio::class);
foreach ([1 => ['e2e-famille@example.test', 'e2e-%02d@example.test'], 2 => ['e2e-en-famille@example.test', 'e2e-en-%02d@example.test']] as $nameColumn => [$familyEmail, $pattern]) {
    $members = [sprintf($pattern, 1), sprintf($pattern, 2)];
    if (!isset($users[$members[0]], $users[$members[1]])) {
        continue;
    }
    $owner = TestUsers::create($container, $familyEmail);
    foreach ($members as $rank => $email) {
        $export = json_encode($transfer->export(TestUsers::findOrCreate($container, $email)), JSON_THROW_ON_ERROR);
        $family->import($owner, null, DEMO_HOUSEHOLD[$nameColumn][$rank], $export);
    }
    echo "Compte famille $familyEmail prêt\n";
}
