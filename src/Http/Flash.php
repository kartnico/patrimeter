<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

/** Message shown only once, on the next page (outside controllers), translated on display. */
final class Flash
{
    public static function add(Request $request, string $type, string|TranslatableInterface $message): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
