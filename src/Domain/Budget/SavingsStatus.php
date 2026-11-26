<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

/** Planned savings payment, compared with the change in its account's balance over the month (SavingsTrack::status()). */
enum SavingsStatus: string
{
    /** Statement of the month not entered yet. */
    case Unknown = 'unknown';
    /** The balance grew by at least the planned payment. */
    case Reached = 'reached';
    /** The balance grew, less than the planned payment. */
    case Below = 'below';
    /** The balance went down. */
    case Decrease = 'decrease';
}
