<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Http\ApiAvailability;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Availability of the API and the MCP in templates: "AI agents" entry of the account menu. */
final class ApiAvailabilityExtension extends AbstractExtension
{
    public function __construct(private readonly ApiAvailability $availability) {}

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('api_access_available', $this->availability->any(...))];
    }
}
