<?php

declare(strict_types=1);

namespace Patrimeter\Application\Maintenance;

use Patrimeter\Application\Reminder\EntryReminders;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Sends the monthly reminders due (each one once a month, on its day): every hour, a run that finds none does nothing. */
#[AsMessageHandler]
final class SendEntryRemindersHandler
{
    public function __construct(
        private readonly EntryReminders $reminders,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(SendEntryReminders $message): void
    {
        $sent = $this->reminders->sendDue();
        if ($sent > 0) {
            $this->logger->info('Rappels de saisie envoyés : {count}.', ['count' => $sent]);
        }
    }
}
