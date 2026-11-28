<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** icon('name') in the templates: an icon of the interface's set (Icons), e.g. {{ icon('home') }}. */
final class IconExtension extends AbstractExtension
{
    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('icon', Icons::svg(...), ['is_safe' => ['html']])];
    }
}
