<?php

declare(strict_types=1);

namespace Patrimeter\Application\Maintenance;

use Patrimeter\Application\Demo\DemoAccounts;
use Patrimeter\Application\Registration;
use Patrimeter\Infrastructure\Persistence\DatabaseMaintenance;
use Patrimeter\Infrastructure\Supervision\Heartbeats;
use Patrimeter\Security\Api\ApiTokens;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\OneTimeTokens;
use Patrimeter\Security\TwoFactor\TrustedBrowsers;
use Patrimeter\Security\UserSessions;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletion of what has expired, outside the users' requests: links sent by email, sign-in log beyond
 * 180 days, API codes and tokens, expired sessions, trusted browsers and demonstration accounts, registrations never
 * confirmed within 24 hours (Registration::purgeUnconfirmed), stale entries of the security cache; then maintenance of
 * SQLite's statistics. The monitoring marker (/sante) records each successful run.
 */
#[AsMessageHandler]
final class PurgeExpiredDataHandler
{
    public function __construct(
        private readonly OneTimeTokens $oneTimeTokens,
        private readonly AuthJournal $journal,
        private readonly ApiTokens $apiTokens,
        private readonly UserSessions $sessions,
        private readonly TrustedBrowsers $trustedBrowsers,
        private readonly DemoAccounts $demo,
        private readonly Registration $registration,
        #[Autowire(service: 'cache.security')]
        private readonly PruneableInterface $securityCache,
        private readonly DatabaseMaintenance $database,
        private readonly LoggerInterface $logger,
        private readonly Heartbeats $heartbeats,
    ) {}

    public function __invoke(PurgeExpiredData $message): void
    {
        $this->oneTimeTokens->purgeExpired();
        $this->journal->purgeOld();
        $this->apiTokens->purgeExpired();
        $this->sessions->purgeExpired();
        $this->trustedBrowsers->purgeExpired();
        $this->demo->purgeExpired();
        $unconfirmed = $this->registration->purgeUnconfirmed();
        if ($unconfirmed > 0) {
            $this->logger->info('Inscriptions non confirmées supprimées : {count}.', ['count' => $unconfirmed]);
        }
        $this->securityCache->prune();
        $this->database->optimize();
        $this->logger->info('Données expirées supprimées.');
        $this->heartbeats->beat(Heartbeats::MAINTENANCE);
    }
}
