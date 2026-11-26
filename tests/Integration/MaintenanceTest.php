<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Patrimeter\Application\Maintenance\CheckLatestVersion;
use Patrimeter\Application\Maintenance\CheckLatestVersionHandler;
use Patrimeter\Application\Maintenance\MaintenanceSchedule;
use Patrimeter\Application\Maintenance\PurgeExpiredData;
use Patrimeter\Application\Maintenance\PurgeExpiredDataHandler;
use Patrimeter\Application\Maintenance\SendEntryReminders;
use Patrimeter\Infrastructure\Persistence\AuthEventRepository;
use Patrimeter\Infrastructure\Supervision\LatestVersionCheck;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\OneTimeTokens;
use Patrimeter\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

/** Scheduled tasks (symfony/scheduler) and email alerts. */
final class MaintenanceTest extends IntegrationTestCase
{
    public function testAnHourlyPurgeAndADailyVersionCheckAreScheduled(): void
    {
        $messages = self::service(MaintenanceSchedule::class)->getSchedule()->getRecurringMessages();

        self::assertCount(3, $messages);
        $scheduled = [];
        foreach ($messages as $message) {
            $trigger = $message->getTrigger();
            self::assertInstanceOf(PeriodicalTrigger::class, $trigger);
            $first = iterator_to_array($message->getMessages(new \Symfony\Component\Scheduler\Generator\MessageContext('maintenance', 'id', $trigger, new \DateTimeImmutable())))[0];
            $scheduled[$first::class] = (string) $trigger;
        }
        self::assertSame([PurgeExpiredData::class => 'every 1 hour', CheckLatestVersion::class => 'every 1 day', SendEntryReminders::class => 'every 1 hour'], $scheduled);
    }

    public function testTheVersionCheckDoesNothingUntilAnAddressIsSet(): void
    {
        (self::service(CheckLatestVersionHandler::class))(new CheckLatestVersion());

        self::assertFalse(self::service(LatestVersionCheck::class)->isEnabled());
        self::assertNull(self::service(LatestVersionCheck::class)->available());
    }

    public function testThePurgeRemovesWhatExpiredAndKeepsTheRest(): void
    {
        $user = self::owner();
        $tokens = self::service(OneTimeTokens::class);
        $tokens->issue($user, OneTimeTokens::EMAIL_VERIFICATION, 3600);
        $events = self::service(AuthEventRepository::class);
        $events->add($user, AuthJournal::LOGIN, true, null, null, '2025-01-01T00:00:00Z');
        $events->add($user, AuthJournal::LOGIN, true, null, null, '2026-11-01T00:00:00Z');
        $cache = self::getContainer()->get('cache.security');
        $cache->save($cache->getItem('perime')->set(1)->expiresAfter(1));
        $cache->save($cache->getItem('valide')->set(1)->expiresAfter(3600));
        self::service(\Doctrine\DBAL\Connection::class)->executeStatement("UPDATE cache_items SET item_lifetime = 1, item_time = 1 WHERE item_id LIKE '%perime'");

        self::clock()->sleep(2 * 86400);
        (self::service(PurgeExpiredDataHandler::class))(new PurgeExpiredData());

        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM one_time_tokens'));
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM auth_events'), 'au-delà de 180 jours seulement');
        self::assertSame(0, self::queryInt("SELECT COUNT(*) FROM cache_items WHERE item_id LIKE '%perime'"));
        self::assertSame(1, self::queryInt("SELECT COUNT(*) FROM cache_items WHERE item_id LIKE '%valide'"));
    }

    public function testTheAlertCommandMailsTheAdministrator(): void
    {
        $command = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:alert'));

        self::assertSame(0, $command->execute(['message' => 'La sauvegarde a échoué.']));

        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        $mail = $messages[0];
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('root@localhost', $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('La sauvegarde a échoué.', (string) $mail->getTextBody());
    }
}
