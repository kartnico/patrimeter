<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Http\SecurityHeadersSubscriber;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

/** CSP violation reports: received without session nor CSRF token, announced in the headers. */
final class CspReportTest extends FunctionalTestCase
{
    public function testPagesAnnounceWhereToReport(): void
    {
        $this->client->request('GET', '/connexion');

        self::assertStringContainsString('report-uri ' . SecurityHeadersSubscriber::CSP_REPORT_PATH, (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertSame('csp="http://localhost/csp/rapport"', $this->client->getResponse()->headers->get('Reporting-Endpoints'));
    }

    public function testBothReportFormatsAreAcceptedWithoutSession(): void
    {
        $this->client->getCookieJar()->clear();
        foreach ([
            'application/csp-report' => '{"csp-report": {"document-uri": "http://localhost/", "effective-directive": "script-src-elem", "blocked-uri": "inline"}}',
            'application/reports+json' => '[{"type": "csp-violation", "body": {"documentURL": "http://localhost/", "effectiveDirective": "img-src", "blockedURL": "https://x.example/a.png"}}]',
            'text/plain' => 'nimporte quoi',
        ] as $type => $body) {
            $this->client->request('POST', '/csp/rapport', server: ['CONTENT_TYPE' => $type], content: $body);
            self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT, $type);
            self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'), 'aucune session ouverte');
        }
    }
}
