<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Symfony\Component\Translation\TranslatableMessage;

/** Wording of the waiting time before a new attempt (translated on display). */
final class RetryDelay
{
    private const int SECONDS_PER_MINUTE = 60;
    private const int MINUTES_THRESHOLD = 120;

    /** "Réessayez dans 4 secondes." or "Réessayez dans 15 minutes." */
    public static function sentence(int $seconds): TranslatableMessage
    {
        if ($seconds >= self::MINUTES_THRESHOLD) {
            return new TranslatableMessage('Réessayez dans %count% minutes.', ['%count%' => (int) ceil($seconds / self::SECONDS_PER_MINUTE)]);
        }

        return new TranslatableMessage('Réessayez dans %count% seconde.|Réessayez dans %count% secondes.', ['%count%' => $seconds]);
    }
}
