<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Event: an account of this owner was created, changed (dates, nature, link…) or deleted. Published by the account
 * repository after each write, so that what depends on the accounts as a whole (the monthly reminder's span) follows.
 */
final class AccountsChanged
{
    public function __construct(public readonly UserId $owner) {}
}
