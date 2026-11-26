<?php

declare(strict_types=1);

namespace Patrimeter\Application;

use Patrimeter\Domain\Month;
use Psr\Clock\ClockInterface;

/**
 * Today's date and current month in the application's time zone.
 *
 * Goes through the injected clock (not the system time) so that the tests can freeze the
 * date; the time zone keeps a server in UTC from changing month a few hours too early.
 */
final class Calendar
{
    private readonly \DateTimeZone $timezone;

    public function __construct(
        private readonly ClockInterface $clock,
        string $timezone,
    ) {
        if ($timezone === '') {
            throw new \InvalidArgumentException('Fuseau horaire vide : définir APP_TIMEZONE (ex. Europe/Paris).');
        }
        $this->timezone = new \DateTimeZone($timezone);
    }

    public function timezone(): \DateTimeZone
    {
        return $this->timezone;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone($this->timezone);
    }

    /** UTC timestamp for the database (YYYY-MM-DDTHH:MM:SSZ), shifted by $offsetSeconds. */
    public function timestamp(int $offsetSeconds = 0): string
    {
        $now = $this->clock->now();

        return $now->setTimestamp($now->getTimestamp() + $offsetSeconds)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    public function currentMonth(): Month
    {
        return Month::fromDateTime($this->now());
    }

    /** Today's date in the YYYY-MM-DD format. */
    public function today(): string
    {
        return $this->now()->format('Y-m-d');
    }
}
