<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** Severity of a recommendation, from the alert to the favourable finding (display order). */
enum AdviceSeverity: string
{
    case Alert = 'alert';
    case Warning = 'warning';
    case Info = 'info';
    case Ok = 'ok';

    /** Display rank: the most severe first. */
    public function rank(): int
    {
        return match ($this) {
            self::Alert => 0,
            self::Warning => 1,
            self::Info => 2,
            self::Ok => 3,
        };
    }
}
