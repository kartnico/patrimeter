<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Application\Demo\DemoAccounts;
use Patrimeter\Http\DemoTour;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\DemoGuard;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\TranslatableMessage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Demonstration mode in the templates: offer of the sign-in page, banner and guided tour of a demonstration account. */
final class DemoExtension extends AbstractExtension
{
    public function __construct(
        private readonly DemoAccounts $demo,
        private readonly CurrentUser $currentUser,
        private readonly DemoTour $tour,
        private readonly RequestStack $requests,
    ) {}

    #[\Override]
    public function getFunctions(): array
    {
        return [
            // Lifetime of a demonstration account, in minutes; null when the mode is disabled.
            new TwigFunction('demo_minutes', fn(): ?int => $this->demo->isEnabled() ? intdiv($this->demo->lifetime, 60) : null),
            // Time when the signed-in demonstration account is deleted ("HH:MM"); null for any other account.
            new TwigFunction('demo_end_time', fn(): ?string => ($user = $this->currentUser->authenticated()) === null ? null : $this->demo->endTime($user)),
            // Guided tour (DemoTour) of the signed-in demonstration account; null for any other account.
            new TwigFunction('demo_tour', $this->tour(...)),
            // True if the signed-in account may use this route: always, except a demonstration account (DemoGuard).
            new TwigFunction('demo_allows', fn(string $route): bool => $this->currentUser->authenticated()?->isDemo() !== true || in_array($route, DemoGuard::ALLOWED_ROUTES, true)),
        ];
    }

    /**
     * @return array{active: bool, number: int, total: int, here: bool, first: bool, last: bool, target: ?string,
     *     title: TranslatableMessage, text: TranslatableMessage}|null
     */
    private function tour(): ?array
    {
        $request = $this->requests->getMainRequest();

        return $request === null || $this->currentUser->authenticated()?->isDemo() !== true ? null : $this->tour->view($request);
    }
}
