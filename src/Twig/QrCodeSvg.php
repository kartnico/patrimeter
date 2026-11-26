<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Endroid\QrCode\Bacon\MatrixFactory;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;

/**
 * QR code in SVG embedded in the page: a single path, without style nor script (strict CSP), drawn in black on white
 * whatever the theme (readability by the apps). The content (otpauth:// address) only appears in the drawing of the
 * modules.
 */
final class QrCodeSvg
{
    private const int QUIET_ZONE = 4;

    public function render(#[\SensitiveParameter] string $data, string $label): string
    {
        $matrix = (new MatrixFactory())->create(new QrCode($data, errorCorrectionLevel: ErrorCorrectionLevel::Medium));
        $count = $matrix->getBlockCount();
        $size = $count + 2 * self::QUIET_ZONE;

        $path = '';
        for ($row = 0; $row < $count; ++$row) {
            for ($column = 0; $column < $count; ++$column) {
                if ($matrix->getBlockValue($row, $column) === 1) {
                    $path .= sprintf('M%d %dh1v1h-1z', $column + self::QUIET_ZONE, $row + self::QUIET_ZONE);
                }
            }
        }

        return sprintf(
            '<svg class="qr-code" viewBox="0 0 %1$d %1$d" role="img" aria-label="%2$s" shape-rendering="crispEdges">'
            . '<rect class="qr-code-background" width="%1$d" height="%1$d"/><path class="qr-code-modules" d="%3$s"/></svg>',
            $size,
            htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            $path,
        );
    }
}
