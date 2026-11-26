<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Http\Flash;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

use function Symfony\Component\Translation\t;

/** After signing out (session already invalidated): message and back to the sign-in page. */
final class LogoutSubscriber implements EventSubscriberInterface
{
    /** After Symfony has invalidated the session. */
    private const int PRIORITY_AFTER_SESSION_INVALIDATION = -100;

    public function __construct(private readonly UrlGeneratorInterface $urls) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => ['onLogout', self::PRIORITY_AFTER_SESSION_INVALIDATION]];
    }

    public function onLogout(LogoutEvent $event): void
    {
        Flash::add($event->getRequest(), 'info', t('Vous êtes déconnecté.'));
        $event->setResponse(new RedirectResponse($this->urls->generate('app_login'), Response::HTTP_SEE_OTHER));
    }
}
