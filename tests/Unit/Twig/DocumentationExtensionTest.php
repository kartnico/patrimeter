<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Twig;

use Patrimeter\Twig\DocumentationExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\TwigFunction;

/** Links to the user documentation: in the language of the page, none without an address. */
final class DocumentationExtensionTest extends TestCase
{
    private static function extension(string $url, bool $onLogin = true, string $locale = 'fr'): DocumentationExtension
    {
        $request = new Request();
        $request->setLocale($locale);
        $requests = new RequestStack();
        $requests->push($request);

        return new DocumentationExtension($requests, $url, $onLogin);
    }

    private static function onLogin(DocumentationExtension $extension): bool
    {
        foreach ($extension->getFunctions() as $function) {
            if ($function->getName() === 'documentation_on_login') {
                $callable = $function->getCallable();
                self::assertIsCallable($callable);

                return (bool) $callable();
            }
        }
        self::fail('documentation_on_login missing');
    }

    public function testTheLinkFollowsTheLanguage(): void
    {
        self::assertSame('https://docs.example/patrimeter/fr/', self::extension('https://docs.example/patrimeter/')->documentationUrl());
        self::assertSame('https://docs.example/patrimeter/en/utilisation/objectifs/', self::extension('https://docs.example/patrimeter', locale: 'en')->documentationUrl('utilisation/objectifs/'));
        self::assertSame(['documentation_url', 'documentation_on_login'], array_map(static fn(TwigFunction $f): string => $f->getName(), self::extension('https://x.example')->getFunctions()));
    }

    public function testNoAddressNoLink(): void
    {
        self::assertNull(self::extension('')->documentationUrl());
        self::assertNull(self::extension('javascript:alert(1)')->documentationUrl());
        self::assertFalse(self::onLogin(self::extension('')));
        self::assertFalse(self::onLogin(self::extension('https://docs.example/', onLogin: false)));
        self::assertTrue(self::onLogin(self::extension('https://docs.example/')));
    }
}
