<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Application\Calendar;
use Patrimeter\Domain\UserId;
use Patrimeter\Http\Flash;
use Patrimeter\Infrastructure\Persistence\UserSessionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

use function Symfony\Component\Translation\t;

/**
 * Sessions of each user, listed on the sign-in and security tab, and signed out one by one or all together.
 *
 * A session is recorded at its first request once the sign-in is complete (second factor passed, TOTP set up): a random
 * identifier kept in the session, never the PHP session identifier. Its last activity is updated at most once a minute.
 * A session signed out from another device ends at its next request: emptied, then back to the sign-in page. The
 * expiry after inactivity and the maximum duration stay those of SessionIdleTimeoutSubscriber; the list only shows the
 * sessions still within them.
 */
final class UserSessions implements EventSubscriberInterface
{
    public const string SESSION_KEY = '_patrimeter_session_id';
    private const string LAST_TOUCH_KEY = '_patrimeter_session_touched';

    /** After the firewall (8), the CSRF check (7) and the mandatory steps of the account (6). */
    private const int PRIORITY = 5;

    /** Before Symfony empties the session at sign-out: its identifier is still there. */
    private const int PRIORITY_BEFORE_SESSION_INVALIDATION = 64;

    /** Seconds between two updates of the last activity. */
    private const int TOUCH_INTERVAL = 60;

    public function __construct(
        private readonly UserSessionRepository $sessions,
        private readonly CurrentUser $currentUser,
        private readonly Calendar $calendar,
        private readonly TokenStorageInterface $tokens,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire(param: 'app.session_idle_timeout')]
        public readonly int $idleTimeout,
        #[Autowire(param: 'app.session_max_lifetime')]
        public readonly int $maxLifetime,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY],
            LogoutEvent::class => ['onLogout', self::PRIORITY_BEFORE_SESSION_INVALIDATION],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession() || $request->attributes->getBoolean('_stateless')) {
            return;
        }
        $user = $this->readyUser();
        if ($user === null) {
            return;
        }

        $session = $request->getSession();
        $id = $this->currentId($request);
        $now = $this->calendar->timestamp();
        if ($id === null) {
            $id = bin2hex(random_bytes(16));
            $userAgent = $request->headers->get('User-Agent');
            $this->sessions->create($user->id, $id, $request->getClientIp(), $userAgent === null ? null : mb_substr($userAgent, 0, AuthJournal::USER_AGENT_MAX), $now);
            $session->set(self::SESSION_KEY, $id);
            $session->set(self::LAST_TOUCH_KEY, $this->calendar->now()->getTimestamp());

            return;
        }

        $time = $this->calendar->now()->getTimestamp();
        $due = self::isTouchDue($session->get(self::LAST_TOUCH_KEY), $time);
        $active = $due ? $this->sessions->touch($user->id, $id, $now) : $this->sessions->isActive($user->id, $id);
        if (!$active) {
            $session->invalidate();
            $this->tokens->setToken(null);
            Flash::add($request, 'info', t('Cette session a été déconnectée depuis un autre appareil.'));
            $event->setResponse(new RedirectResponse($this->urls->generate('app_login'), Response::HTTP_SEE_OTHER));

            return;
        }
        if ($due) {
            $session->set(self::LAST_TOUCH_KEY, $time);
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        $id = $this->currentId($event->getRequest());
        if ($user instanceof User && $id !== null) {
            $this->sessions->delete($user->id, $id);
        }
    }

    /**
     * Sessions of the user still open (neither signed out, nor expired), the current one marked.
     *
     * @return list<array{id: string, ip: ?string, user_agent: ?string, created_at: string, last_seen_at: string, current: bool}>
     */
    public function list(Request $request, UserId $user): array
    {
        $current = $this->currentId($request);

        return array_map(
            static fn(array $row): array => $row + ['current' => $row['id'] === $current],
            $this->sessions->active($user, $this->calendar->timestamp(-$this->idleTimeout), $this->calendar->timestamp(-$this->maxLifetime)),
        );
    }

    /** Signs out one session of the user, never the current one; false if there is no such session. */
    public function revoke(Request $request, UserId $user, string $id): bool
    {
        return $id !== $this->currentId($request) && $this->sessions->revoke($user, $id, $this->calendar->timestamp());
    }

    /** Signs out all the user's sessions but the current one (null request: all of them, from the console). */
    public function revokeOthers(?Request $request, UserId $user): void
    {
        $this->sessions->revokeAll($user, $request === null ? null : $this->currentId($request), $this->calendar->timestamp());
    }

    /** Forgets the sessions without activity for longer than the maximum duration (scheduled task). */
    public function purgeExpired(): void
    {
        $this->sessions->purgeInactive($this->calendar->timestamp(-$this->maxLifetime));
    }

    private function currentId(Request $request): ?string
    {
        return $request->hasSession() ? self::stringOrNull($request->getSession()->get(self::SESSION_KEY)) : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** True if the last activity was recorded more than TOUCH_INTERVAL seconds ago, or never. */
    private static function isTouchDue(mixed $touchedAt, int $time): bool
    {
        return !is_int($touchedAt) || $time - $touchedAt >= self::TOUCH_INTERVAL;
    }

    private function readyUser(): ?User
    {
        $user = $this->currentUser->authenticated();

        return $user !== null && $user->isReady() ? $user : null;
    }
}
