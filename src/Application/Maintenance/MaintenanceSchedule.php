<?php

declare(strict_types=1);

namespace Patrimeter\Application\Maintenance;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Scheduled tasks (symfony/scheduler): consumed by "bin/console messenger:consume
 * scheduler_maintenance", which the patrimeter-scheduler systemd service runs.
 */
#[AsSchedule('maintenance')]
final class MaintenanceSchedule implements ScheduleProviderInterface
{
    public function __construct(
        #[Autowire(service: 'cache.security')]
        private readonly CacheInterface $state,
    ) {}

    #[\Override]
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('1 hour', new PurgeExpiredData()))
            // Latest published version, once a day (does nothing unless UPDATE_CHECK_URL is set).
            ->add(RecurringMessage::every('1 day', new CheckLatestVersion()))
            // Monthly reminders to enter the balances, chosen by the users (each one once a month, on its day).
            ->add(RecurringMessage::every('1 hour', new SendEntryReminders()))
            // After a stop, a single run catches up on the missed runs.
            ->stateful($this->state)
            ->processOnlyLastMissedRun(true);
    }
}
