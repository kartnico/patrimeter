<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Links to the user documentation (Zensical site, one folder per language): in the account menu, and on the sign-in
 * page unless DOCUMENTATION_ON_LOGIN is false. DOCUMENTATION_URL empty: no link at all.
 */
final class DocumentationExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire('%env(DOCUMENTATION_URL)%')]
        private readonly string $url,
        #[Autowire('%env(bool:DOCUMENTATION_ON_LOGIN)%')]
        private readonly bool $onLogin,
    ) {}

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('documentation_url', $this->documentationUrl(...)),
            new TwigFunction('documentation_on_login', fn(): bool => $this->onLogin && $this->documentationUrl() !== null),
        ];
    }

    /** Address of the documentation in the language of the page (a page of it if given), or null if none is set. */
    public function documentationUrl(string $page = ''): ?string
    {
        $base = trim($this->url);
        if ($base === '' || preg_match('#\Ahttps?://#', $base) !== 1) {
            return null;
        }
        $locale = $this->requests->getCurrentRequest()?->getLocale() === 'en' ? 'en' : 'fr';

        return rtrim($base, '/') . '/' . $locale . '/' . ltrim($page, '/');
    }
}
