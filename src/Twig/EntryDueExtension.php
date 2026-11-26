<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Application\Calendar;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Security\CurrentUser;
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
        private readonly StatementRepository $statements,
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

    /** True if the signed-in user has accounts and no statement for the current month (computed once per request). */
    public function isDue(): bool
    {
        if ($this->due === null) {
            $user = $this->currentUser->authenticated();
            $this->due = $user !== null && $user->isReady() && $this->statements->isMonthToEnter($user->id, $this->calendar->currentMonth());
        }

        return $this->due;
    }
}
