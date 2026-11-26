<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Security\Vault\DataKeys;
use Patrimeter\Security\Vault\Vault;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Mandatory steps before reaching the account, in this order: set up TOTP, unlock the vault (session key
 * lost), write down the recovery key. Any other request, POST included, is redirected without being run.
 * Signing out and switching the theme remain possible. The repositories refuse such a user anyway
 * (CurrentUser, Vault).
 */
final class AccountGateSubscriber implements EventSubscriberInterface
{
    private const array ALWAYS_ALLOWED = ['app_logout', 'app_theme', 'app_locale'];
    private const array TOTP_ROUTES = ['app_totp_setup', 'app_totp_enable'];
    private const array UNLOCK_ROUTES = ['app_vault_unlock', 'app_vault_unlock_submit'];
    private const array RECOVERY_ROUTES = ['app_recovery_key', 'app_recovery_key_confirm'];

    /** After the firewall (8) and the CSRF check (7). */
    private const int PRIORITY = 6;

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly Vault $vault,
        private readonly DataKeys $dataKeys,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $route = $event->getRequest()->attributes->getString('_route');
        if (!$event->isMainRequest() || in_array($route, self::ALWAYS_ALLOWED, true) || $event->getRequest()->attributes->getBoolean('_stateless')) {
            return;
        }
        $user = $this->currentUser->authenticated();
        // A demonstration account has neither TOTP nor recovery key: DemoGuard handles it.
        if ($user === null || $user->isDemo()) {
            return;
        }
        [$target, $allowed] = match (true) {
            !$user->isTotpAuthenticationEnabled() => ['app_totp_setup', self::TOTP_ROUTES],
            !$this->vault->isUnlocked($user->id) => ['app_vault_unlock', self::UNLOCK_ROUTES],
            !$this->dataKeys->hasRecovery($user->id) => ['app_recovery_key', self::RECOVERY_ROUTES],
            default => [null, []],
        };
        if ($target !== null && !in_array($route, $allowed, true)) {
            $event->setResponse(new RedirectResponse($this->urls->generate($target), Response::HTTP_SEE_OTHER));
        }
    }
}
