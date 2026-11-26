<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Twig;

use Patrimeter\Twig\QrCodeSvg;
use PHPUnit\Framework\TestCase;

final class QrCodeSvgTest extends TestCase
{
    public function testRendersASingleInlinePathWithoutStyleOrScript(): void
    {
        $svg = (new QrCodeSvg())->render('otpauth://totp/Patrimeter:marie%40example.fr?secret=JBSWY3DPEHPK3PXP&issuer=Patrimeter', 'QR code "à scanner"');

        self::assertMatchesRegularExpression('~\A<svg class="qr-code" viewBox="0 0 (\d+) \1" role="img" aria-label="QR code &quot;à scanner&quot;" shape-rendering="crispEdges"><rect class="qr-code-background" width="\1" height="\1"/><path class="qr-code-modules" d="(M\d+ \d+h1v1h-1z)+"/></svg>\z~u', $svg);
        self::assertStringNotContainsString('style', $svg);
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $svg, 'le secret n\'apparaît pas en clair');
    }

    public function testQuietZoneSurroundsTheModules(): void
    {
        $svg = (new QrCodeSvg())->render('x', 'QR');
        if (preg_match('~viewBox="0 0 (\d+) ~', $svg, $size) !== 1 || preg_match_all('~M(\d+) (\d+)~', $svg, $modules) < 1) {
            self::fail('SVG inattendu');
        }
        $columns = array_map(intval(...), $modules[1]);
        $rows = array_map(intval(...), $modules[2]);

        self::assertSame(4, min([PHP_INT_MAX, ...$columns]), 'marge de 4 modules à gauche');
        self::assertSame((int) $size[1] - 5, max([0, ...$rows]), 'marge de 4 modules en bas');
    }
}
