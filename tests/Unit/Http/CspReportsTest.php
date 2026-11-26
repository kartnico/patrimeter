<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Http;

use Patrimeter\Http\CspReports;
use PHPUnit\Framework\TestCase;

final class CspReportsTest extends TestCase
{
    public function testReportUriFormatIsReadAndCleaned(): void
    {
        $body = json_encode(['csp-report' => [
            'document-uri' => 'https://patrimeter.example/mot-de-passe-oublie/AAAAAAAAAAAAAAAAAAAAAA.BBBBBBBBBBBBBBBBBBBBBBBBBBBBBB?x=1#frag',
            'violated-directive' => 'script-src-elem',
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/x.js?token=secret',
            'source-file' => 'https://patrimeter.example/assets/app.js',
            'script-sample' => 'alert(document.cookie)',
        ]], JSON_THROW_ON_ERROR);

        self::assertSame([[
            'directive' => 'script-src-elem',
            'blocked' => 'https://evil.example/x.js',
            'document' => 'https://patrimeter.example/mot-de-passe-oublie/[jeton]',
            'source' => 'https://patrimeter.example/assets/app.js',
        ]], CspReports::parse($body));
        self::assertStringNotContainsString('cookie', json_encode(CspReports::parse($body), JSON_THROW_ON_ERROR), 'extrait de code jamais conservé');
    }

    public function testReportingApiFormatIsRead(): void
    {
        $body = json_encode([
            ['type' => 'csp-violation', 'body' => ['documentURL' => 'https://patrimeter.example/', 'effectiveDirective' => 'style-src-attr', 'blockedURL' => 'inline', 'sample' => 'color:red']],
            ['type' => 'deprecation', 'body' => ['id' => 'x']],
        ], JSON_THROW_ON_ERROR);

        self::assertSame([['directive' => 'style-src-attr', 'blocked' => 'inline', 'document' => 'https://patrimeter.example/', 'source' => '-']], CspReports::parse($body));
    }

    public function testAnythingElseIsIgnored(): void
    {
        self::assertSame([], CspReports::parse('pas du JSON'));
        self::assertSame([], CspReports::parse('"texte"'));
        self::assertSame([], CspReports::parse('{"autre": 1}'));
        self::assertCount(10, CspReports::parse(json_encode(array_fill(0, 50, ['type' => 'csp-violation', 'body' => ['effectiveDirective' => "img-src\x01"]]), JSON_THROW_ON_ERROR)), 'dix rapports au plus');
    }
}
