<?php

declare(strict_types=1);

namespace Patrimeter\Application\Reminder;

use Patrimeter\Application\Calendar;
use Patrimeter\Application\Game\Game;
use Patrimeter\Domain\UserId;
use Patrimeter\Http\PublicUrl;
use Patrimeter\Infrastructure\Mail\AccountMailer;
use Patrimeter\Infrastructure\Persistence\EntryReminderRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

/**
 * Monthly email reminder to enter the balances, chosen by each user (off by default). The email holds no amount and
 * no status: outside a session, the server cannot read the data. With the challenges on, it gives the entry streak
 * and the next star within reach, worked out from the months entered (in clear) and the stars kept. It leaves on the chosen day of the month from
 * SEND_HOUR, once, and only if no balance of the month is entered yet. Each email carries a single-use unsubscribe
 * link, which works without signing in; only its fingerprint is kept.
 */
final class EntryReminders
{
    public const int DEFAULT_DAY = 25;
    /** Hour (application's time zone) from which the reminder of the day leaves: never in the night. */
    public const int SEND_HOUR = 9;

    public function __construct(
        private readonly EntryReminderRepository $reminders,
        private readonly AccountMailer $mailer,
        private readonly PublicUrl $urls,
        private readonly Calendar $calendar,
        private readonly TranslatorInterface $translator,
        private readonly Game $game,
        #[Autowire('%env(MAIL_FROM)%')]
        private readonly string $from,
    ) {}

    /** Address the reminders come from (MAIL_FROM, already shown in every email), or null if it is not readable. */
    public function sender(): ?string
    {
        try {
            return Address::create($this->from)->getAddress();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Day of the month of the user's reminder, or null if they receive none. */
    public function day(UserId $user): ?int
    {
        return $this->reminders->find($user)['day'] ?? null;
    }

    /** Receives the reminder on this day (1 to 28), in this language; null: no more reminder. */
    public function choose(UserId $user, ?int $day, string $locale): void
    {
        if ($day === null) {
            $this->reminders->delete($user);

            return;
        }
        $this->reminders->save($user, max(1, min(28, $day)), $locale === 'en' ? 'en' : 'fr');
    }

    public function canUnsubscribe(string $token): bool
    {
        return $this->reminders->existsWithUnsubscribeHash(self::hash($token));
    }

    /** Unsubscribes with the link of the last email; false if the link is unknown (already used, or replaced). */
    public function unsubscribe(string $token): bool
    {
        return $this->reminders->deleteByUnsubscribeHash(self::hash($token));
    }

    /** Sends the reminders due today; returns how many left. */
    public function sendDue(): int
    {
        $now = $this->calendar->now();
        $month = $this->calendar->currentMonth();
        // Before SEND_HOUR, the reminders of the day wait: only those of the days before are due.
        $day = (int) $now->format('j') - ((int) $now->format('G') < self::SEND_HOUR ? 1 : 0);
        $sent = 0;
        foreach ($this->reminders->due($month->toString(), $day) as $due) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $hash = self::hash($token);
            if (!$this->reminders->claim($due['user'], $month->toString(), $hash, $this->calendar->now()->getTimestamp())) {
                continue;
            }
            try {
                $delivered = $this->inLocale($due['locale'], fn(): bool => $this->mailer->sendNow($due['email'], t('Patrimeter : vos soldes de %month% sont à saisir', ['%month%' => $month->label($due['locale'])]), 'entry_reminder', [
                    'month' => $month->label($due['locale']),
                    'entry_url' => $this->urls->generate('app_entry'),
                    'unsubscribe_url' => $this->urls->generate('app_reminder_unsubscribe', ['token' => $token]),
                    'streak' => $this->game->streakNudge($due['user'], $month),
                ], $due['locale']));
                if ($delivered) {
                    $this->reminders->markSent($due['user'], $month->toString(), $hash);
                    ++$sent;
                }
            } finally {
                $this->reminders->release($due['user'], $hash);
            }
        }

        return $sent;
    }

    /** @param callable(): bool $send */
    private function inLocale(string $locale, callable $send): bool
    {
        if (!$this->translator instanceof LocaleAwareInterface) {
            return $send();
        }
        $previous = $this->translator->getLocale();
        $this->translator->setLocale($locale);
        try {
            return $send();
        } finally {
            $this->translator->setLocale($previous);
        }
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
