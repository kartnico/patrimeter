<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies the chosen language (cookie) before Symfony's LocaleListener (priority 16): the _locale attribute
 * then wins over the browser's language.
 */
final class LocaleSubscriber
{
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 17)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $locale = LocalePreference::chosen($request);
        if ($locale !== null && !$request->attributes->has('_locale')) {
            $request->attributes->set('_locale', $locale);
        }
    }
}
