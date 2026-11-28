<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Application\Calendar;
use Patrimeter\Application\MonthlyEntry;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\Vault\Vault;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Whether the balances of the current month are still to enter, for the signed-in user: the Monthly entry item of the
 * main navigation is then highlighted.
 */
final class EntryDueExtension extends AbstractExtension implements ResetInterface
{
    private ?bool $due = null;

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly MonthlyEntry $entry,
        private readonly Vault $vault,
        private readonly Calendar $calendar,
    ) {}

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('entry_due', $this->isDue(...))];
    }

    /** Next request: computed again. */
    #[\Override]
    public function reset(): void
    {
        $this->due = null;
    }

    /**
     * True if the current month waits for the signed-in user's balances: an account active in it and nothing entered
     * yet (computed once per request, with the data unlocked: the accounts are encrypted).
     */
    public function isDue(): bool
    {
        if ($this->due === null) {
            $user = $this->currentUser->authenticated();
            $this->due = $user !== null && $user->isReady() && $this->vault->isUnlocked($user->id)
                && $this->entry->isDue($user->id, $this->calendar->currentMonth());
        }

        return $this->due;
    }
}
