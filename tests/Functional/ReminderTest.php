<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Application\Game\Game;
use Patrimeter\Application\Reminder\EntryReminders;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\EntryReminderRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/**
 * Monthly email reminder to enter the balances (clock frozen on 2026-11-15): chosen in the profile, sent once a month
 * from its day if the month is not entered, without any amount, and unsubscribed through the link of the email.
 */
final class ReminderTest extends FunctionalTestCase
{
    public function testTheReminderIsChosenInTheProfile(): void
    {
        $this->logIn();
        $crawler = $this->client->request('GET', '/securite');
        self::assertSame('25', $crawler->filter('#reminder-day option[selected]')->attr('value'), 'default day');
        self::assertCount(0, $crawler->filter('input[name="enabled"][checked]'));

        $this->submit('/securite/rappel', ['enabled' => '1', 'day' => '10'], '/securite');
        self::assertResponseRedirects('/securite#reminder-title', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('un e-mail le 10 de chaque mois', $this->flashes());
        self::assertCount(1, $crawler->filter('input[name="enabled"][checked]'));
        self::assertSame(10, self::service(EntryReminders::class)->day(self::owner()));

        $this->submit('/securite/rappel', ['day' => '10'], '/securite');
        self::assertNull(self::service(EntryReminders::class)->day(self::owner()));
    }

    public function testTheReminderLeavesOnceFromItsDayIfTheMonthIsNotEntered(): void
    {
        self::createAccount('Compte courant', 'compte_courant', '2025-01-01');
        $reminders = self::service(EntryReminders::class);
        $reminders->choose(self::owner(), 10, 'fr');
        $later = TestUsers::create(static::getContainer(), 'plus-tard@example.test');
        $reminders->choose($later, 20, 'fr');
        $entered = TestUsers::create(static::getContainer(), 'deja-saisi@example.test');
        $reminders->choose($entered, 1, 'en');
        $account = self::createAccount('Livret', 'livret_a', '2025-01-01', $entered);
        self::service(StatementRepository::class)->save($entered, $account, Month::fromString('2026-11'), 123_456);

        self::assertSame(1, $reminders->sendDue());
        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        $email = $messages[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(TestUsers::EMAIL, $email->getTo()[0]->getAddress());
        self::assertSame('Patrimeter : vos soldes de novembre 2026 sont à saisir', $email->getSubject());
        $body = (string) $email->getTextBody();
        self::assertStringContainsString('/saisie', $body);
        self::assertMatchesRegularExpression('#/rappel/desabonnement/[A-Za-z0-9_-]{43}#', $body);
        self::assertStringNotContainsString('€', $body, 'no amount');

        self::assertSame(0, $reminders->sendDue(), 'once a month');
    }

    public function testAFailedTransportCanBeRetriedWithoutCountingTheFailure(): void
    {
        self::createAccount('Compte courant', 'compte_courant', '2025-01-01');
        $reminders = self::service(EntryReminders::class);
        $reminders->choose(self::owner(), 10, 'fr');
        $events = self::service(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class);
        $fail = static function (): never {
            throw new \Symfony\Component\Mailer\Exception\TransportException('Test failure');
        };
        $events->addListener(\Symfony\Component\Mailer\Event\MessageEvent::class, $fail);
        try {
            self::assertSame(0, $reminders->sendDue());
        } finally {
            $events->removeListener(\Symfony\Component\Mailer\Event\MessageEvent::class, $fail);
        }
        self::assertSame(1, $reminders->sendDue());
        self::assertSame(0, $reminders->sendDue());
    }

    public function testTheReminderOfTheDayWaitsUntilNineInTheMorning(): void
    {
        self::createAccount('Compte courant', 'compte_courant', '2025-01-01');
        $reminders = self::service(EntryReminders::class);
        $reminders->choose(self::owner(), 15, 'fr');
        self::clock()->modify('2026-11-15 08:59:00');
        self::assertSame(0, $reminders->sendDue(), 'never at night');
        self::clock()->modify('2026-11-15 09:00:00');
        self::assertSame(1, $reminders->sendDue());
    }

    public function testTheReminderOfAnEarlierDayLeavesBeforeNine(): void
    {
        self::createAccount('Compte courant', 'compte_courant', '2025-01-01');
        $reminders = self::service(EntryReminders::class);
        $reminders->choose(self::owner(), 14, 'fr');
        self::clock()->modify('2026-11-15 07:00:00');
        self::assertSame(1, $reminders->sendDue());
    }

    public function testTheProfileGivesTheHourAndTheSenderOfTheReminder(): void
    {
        $this->logIn();
        $this->client->request('GET', '/securite');
        self::assertSelectorTextContains('#reminder-title + p', 'vers 9 h');
        self::assertSelectorTextContains('#reminder-title ~ .hint', 'Expéditeur : patrimeter@localhost');
    }

    public function testTheLinkOfTheEmailUnsubscribesWithoutSigningIn(): void
    {
        self::createAccount('Compte courant', 'compte_courant', '2025-01-01');
        $reminders = self::service(EntryReminders::class);
        $reminders->choose(self::owner(), 1, 'en');
        $reminders->sendDue();
        $message = self::getMailerMessages()[0] ?? null;
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('Patrimeter: your balances of November 2026 are to be entered', $message->getSubject(), 'in the language chosen');
        self::assertSame(1, preg_match('#(/rappel/desabonnement/[A-Za-z0-9_-]{43})#', (string) $message->getTextBody(), $link));
        $path = $link[1];

        $crawler = $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertNotNull($reminders->day(self::owner()), 'the page alone unsubscribes nobody');
        $this->client->submit($crawler->selectButton('Ne plus recevoir le rappel')->form());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('C\'est fait', $this->client->getCrawler()->filter('main')->text());
        self::assertNull($reminders->day(self::owner()));

        $this->submit($path, [], $path);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->client->request('GET', '/rappel/desabonnement/' . str_repeat('A', 43));
        self::assertStringContainsString('Ce lien n\'est plus valable', $this->client->getCrawler()->filter('main')->text());
    }

    public function testNoReminderWhenNoAccountIsActiveInTheMonth(): void
    {
        $reminders = self::service(EntryReminders::class);
        // Closed in October: nothing to enter in November. Opened in January: nothing yet.
        $closed = self::createAccount('Ancien livret', 'livret_a', '2024-01-01');
        self::service(AccountRepository::class)->archive(self::owner(), $closed, '2026-10-15');
        $reminders->choose(self::owner(), 10, 'fr');
        $future = TestUsers::create(static::getContainer(), 'futur@example.test');
        $reminders->choose($future, 10, 'fr');
        self::createAccount('Livret', 'livret_a', '2027-01-04', $future);
        $empty = TestUsers::create(static::getContainer(), 'sans-compte@example.test');
        $reminders->choose($empty, 10, 'fr');

        self::assertSame(0, $reminders->sendDue());

        // Reopened: the span follows the change of the account, and the reminder leaves.
        self::service(AccountRepository::class)->unarchive(self::owner(), $closed);
        self::assertSame(1, $reminders->sendDue());
        $email = self::getMailerMessages()[0] ?? null;
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(TestUsers::EMAIL, $email->getTo()[0]->getAddress());
    }

    public function testAReminderChosenBeforeTheSpanWasRecordedStillLeaves(): void
    {
        // A reminder kept from 3.6 (span unknown until the next account change): sent as before.
        self::service(EntryReminderRepository::class)->save(self::owner(), 10, 'fr');

        self::assertSame(1, self::service(EntryReminders::class)->sendDue());
    }

    public function testTheWealthPagePointsOutAMonthNotEnteredFromThe20th(): void
    {
        $account = self::createAccount('Livret', 'livret_a', '2025-01-01');
        self::service(StatementRepository::class)->save(self::owner(), $account, Month::fromString('2026-10'), 100_000);
        $this->logIn();
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.entry-due', 'the 15th: too early');

        // Ten days later: the session has expired, sign in again.
        self::clock()->modify('+10 days');
        $this->client->getCookieJar()->clear();
        $this->logIn();
        $crawler = $this->client->request('GET', '/');
        self::assertStringContainsString('Les soldes de novembre 2026 ne sont pas encore saisis.', $crawler->filter('.entry-due')->text());
        $this->client->request('GET', '/?mois=2026-10');
        self::assertSelectorNotExists('.entry-due', 'only for the current month');

        // Closed in October: nothing to enter in November, neither in the page nor in the navigation.
        self::service(AccountRepository::class)->archive(self::owner(), $account, '2026-10-31');
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.entry-due');
        self::assertSelectorNotExists('nav.nav a.needs-entry');
    }

    public function testTheReminderGivesTheStreakAndTheNextStarWithoutAnyAmount(): void
    {
        $account = self::createAccount('Livret A', 'livret_a', '2025-01-01');
        foreach (['2026-09', '2026-10'] as $month) {
            self::service(StatementRepository::class)->save(self::owner(), $account, Month::fromString($month), 500_000);
        }
        $reminders = self::service(EntryReminders::class);
        $reminders->choose(self::owner(), 10, 'fr');

        self::assertSame(1, $reminders->sendDue());
        $body = (string) self::lastEmail()->getTextBody();
        self::assertStringContainsString('Série de saisies : 2 mois de suite.', $body);
        self::assertStringContainsString('En saisissant novembre 2026, votre série passe à 3 mois : une nouvelle étoile du défi « Série de saisies ».', $body);
        self::assertStringContainsString('ce n\'est pas un conseil en investissement', $body);
        self::assertStringNotContainsString('5 000', $body, 'no amount');
        self::assertStringNotContainsString('€', $body, 'no amount');
    }

    public function testTheReminderInEnglishCountsTheMonths(): void
    {
        $account = self::createAccount('Livret A', 'livret_a', '2025-01-01');
        self::service(StatementRepository::class)->save(self::owner(), $account, Month::fromString('2026-10'), 500_000);
        self::service(EntryReminders::class)->choose(self::owner(), 10, 'en');

        self::service(EntryReminders::class)->sendDue();
        $body = (string) self::lastEmail()->getTextBody();
        self::assertStringContainsString('Entry streak: 1 month in a row.', $body);
        self::assertStringContainsString('By entering November 2026, your streak goes up to 2 months; next star at 3 months in a row.', $body);
    }

    public function testWithoutTheChallengesTheReminderSaysNothingOfThem(): void
    {
        self::createAccount('Compte courant', 'compte_courant', '2025-01-01');
        self::service(Game::class)->setEnabled(self::owner(), false);
        self::service(EntryReminders::class)->choose(self::owner(), 10, 'fr');

        self::service(EntryReminders::class)->sendDue();
        $body = (string) self::lastEmail()->getTextBody();
        self::assertStringNotContainsString('série', $body);
        self::assertStringNotContainsString('conseil en investissement', $body);
    }

    private static function lastEmail(): Email
    {
        $messages = self::getMailerMessages();
        $email = end($messages);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    public function testADemoAccountNeverReceivesAReminder(): void
    {
        $this->submit('/demo', [], '/connexion');
        $demo = self::queryInt('SELECT COUNT(*) FROM demo_accounts');
        self::assertSame(1, $demo);
        $id = self::service(\Doctrine\DBAL\Connection::class)->fetchOne('SELECT user_id FROM demo_accounts');
        self::assertIsString($id);
        self::service(EntryReminders::class)->choose(\Patrimeter\Domain\UserId::fromString($id), 1, 'fr');

        self::assertSame(0, self::service(EntryReminders::class)->sendDue());
    }
}
