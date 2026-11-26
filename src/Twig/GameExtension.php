<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Application\Game\Game;
use Patrimeter\Domain\Game\SaverStyle;
use Patrimeter\Security\CurrentUser;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Whether the challenges are shown to the signed-in user (instance setting and their own choice): the Défis item of the
 * main navigation and the first-steps card depend on it. Also the saver style they declared (profile page).
 */
final class GameExtension extends AbstractExtension implements ResetInterface
{
    private ?bool $enabled = null;

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly Game $game,
    ) {}

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('game_enabled', $this->isEnabled(...)),
            new TwigFunction('game_offered', $this->game->isOffered(...)),
            new TwigFunction('saver_style', $this->style(...)),
        ];
    }

    #[\Override]
    public function reset(): void
    {
        $this->enabled = null;
    }

    public function style(): ?SaverStyle
    {
        $user = $this->currentUser->authenticated();

        return $user === null ? null : $this->game->style($user->id);
    }

    /** Computed once per request. */
    public function isEnabled(): bool
    {
        if ($this->enabled === null) {
            $user = $this->currentUser->authenticated();
            $this->enabled = $user !== null && $user->isReady() && $this->game->isEnabled($user->id);
        }

        return $this->enabled;
    }
}
