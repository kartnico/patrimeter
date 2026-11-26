<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Http\Flash;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function Symfony\Component\Translation\t;

/**
 * Expiry of the session after a period of inactivity, and at the latest after a maximum duration since
 * the sign-in.
 *
 * Runs just before the firewall: an expired session is emptied and gets a new identifier, then the
 * firewall redirects to the sign-in page as for any anonymous visitor. The last activity is dated with
 * the application's clock (not the system time) to stay testable.
 */
final class SessionIdleTimeoutSubscriber implements EventSubscriberInterface
{
    /** Symfony's firewall listens with priority 8. */
    private const int PRIORITY_BEFORE_FIREWALL = 9;
    private const string LAST_ACTIVITY_KEY = '_patrimeter_last_activity';
    private const string AUTHENTICATED_SESSION_KEY = '_security_main';

    public function __construct(
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.session_idle_timeout')]
        private readonly int $idleTimeout,
        #[Autowire(param: 'app.session_max_lifetime')]
        private readonly int $maxLifetime,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY_BEFORE_FIREWALL]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession() || $request->attributes->getBoolean('_stateless')) {
            return;
        }

        $session = $request->getSession();
        $now = $this->clock->now()->getTimestamp();
        if ($this->hasExpired($session->get(self::LAST_ACTIVITY_KEY), $session->get(AuthenticationSubscriber::LOGIN_AT_KEY), $now)) {
            $wasAuthenticated = $session->has(self::AUTHENTICATED_SESSION_KEY);
            $session->invalidate();
            if ($wasAuthenticated) {
                Flash::add($request, 'info', t('Votre session a expiré, veuillez vous reconnecter.'));
            }
        }
        $session->set(self::LAST_ACTIVITY_KEY, $now);
    }

    private function hasExpired(mixed $lastActivity, mixed $loginAt, int $now): bool
    {
        return (is_int($lastActivity) && $now - $lastActivity > $this->idleTimeout)
            || (is_int($loginAt) && $now - $loginAt > $this->maxLifetime);
    }
}
