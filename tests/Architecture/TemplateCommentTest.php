<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Twig comments never name the root tag: SonarQube reads the templates as HTML, comments included, and took the
 * "<html>" of a comment for a page without doctype nor language (quality gate of 3.7.0 failed).
 */
final class TemplateCommentTest extends TestCase
{
    public function testNoTwigCommentContainsAnHtmlTag(): void
    {
        $files = (new Finder())->files()->in(__DIR__ . '/../../templates')->name('*.twig');
        self::assertTrue($files->hasResults());
        foreach ($files as $file) {
            preg_match_all('/\{#.*?#\}/s', $file->getContents(), $comments);
            foreach ($comments[0] as $comment) {
                self::assertDoesNotMatchRegularExpression('/<\/?html\b/i', $comment, $file->getRelativePathname());
            }
        }
    }
}
