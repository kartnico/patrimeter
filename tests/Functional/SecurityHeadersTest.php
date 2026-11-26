<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Http\SecurityHeadersSubscriber;
use Patrimeter\Tests\Support\FunctionalTestCase;

final class SecurityHeadersTest extends FunctionalTestCase
{
    public function testEveryPageCarriesTheSecurityHeaders(): void
    {
        $this->client->request('GET', '/connexion');

        self::assertResponseHeaderSame('Content-Security-Policy', SecurityHeadersSubscriber::CONTENT_SECURITY_POLICY);
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertResponseHeaderSame('Cross-Origin-Opener-Policy', 'same-origin');
        self::assertResponseHeaderSame('Cross-Origin-Resource-Policy', 'same-origin');
        self::assertResponseHeaderSame('Permissions-Policy', SecurityHeadersSubscriber::PERMISSIONS_POLICY);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseNotHasHeader('Strict-Transport-Security');
    }

    public function testContentSecurityPolicyForbidsInlineCodeAndExternalResources(): void
    {
        $csp = SecurityHeadersSubscriber::CONTENT_SECURITY_POLICY;

        self::assertStringContainsString("default-src 'none'", $csp);
        self::assertStringContainsString("script-src 'self';", $csp);
        self::assertStringContainsString("style-src 'self';", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function testHstsIsSentOverHttpsOnly(): void
    {
        $this->client->request('GET', '/connexion', server: ['HTTPS' => 'on']);

        self::assertResponseHeaderSame('Strict-Transport-Security', 'max-age=31536000');
    }

    public function testSessionCookieIsHttpOnlyAndStrict(): void
    {
        $this->client->request('GET', '/connexion');
        $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;

        // In tests, the session storage is simulated (MOCKSESSID name); the attributes are real.
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());
    }

    public function testRenderedPagesContainNoInlineScriptOrStyle(): void
    {
        $this->logIn();
        foreach (['/', '/comptes', '/comptes/nouveau', '/saisie', '/budget/saisie', '/biens'] as $page) {
            $this->client->request('GET', $page);
            $html = $this->body();
            self::assertDoesNotMatchRegularExpression('/\sstyle=|<style|\son[a-z]+=/i', $html, "$page : style ou gestionnaire inline");
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\s(src|type="application\/json")=?)[^>]*>/i', $html, "$page : script inline exécutable");
        }
    }
}
