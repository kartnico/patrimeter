<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Addresses of the API, of the MCP service and of the OAuth server of a disabled service (ApiAvailability): 404, as if
 * they did not exist, before the routing and the firewall.
 */
final class ApiAvailabilitySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ApiAvailability $availability) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 64]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && !$this->availability->allows($event->getRequest()->getPathInfo())) {
            throw new NotFoundHttpException('Service désactivé sur cette instance.');
        }
    }
}
