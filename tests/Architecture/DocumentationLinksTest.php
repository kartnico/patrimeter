<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Markdown pages of the repository, read on GitLab: every relative link leads to an existing file. The
 * documentation site is checked by Zensical in strict mode (documentation/build.sh).
 */
final class DocumentationLinksTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    public function testRelativeLinksLeadToExistingFiles(): void
    {
        $pages = [...self::files('*.md'), ...self::files('docs/*.md'), ...self::files('tests/Fixtures/regression/*.md')];
        self::assertNotSame([], $pages);

        $broken = [];
        foreach ($pages as $page) {
            $content = file_get_contents(self::ROOT . "/$page");
            self::assertIsString($content);
            // Code blocks contain no links.
            $content = (string) preg_replace('/^```.*?^```/ms', '', $content);
            preg_match_all('/\]\(([^)\s]+)\)/', $content, $links);
            foreach ($links[1] as $target) {
                $path = explode('#', $target, 2)[0];
                if ($path === '' || str_contains($path, '://') || str_starts_with($path, 'mailto:')) {
                    continue;
                }
                if (!file_exists(self::ROOT . '/' . dirname($page) . '/' . $path)) {
                    $broken[] = "$page → $target";
                }
            }
        }

        self::assertSame([], $broken, 'Liens relatifs cassés');
    }

    /**
     * Pages of the documentation site (docs/, documentation/fr|en/, CHANGELOG, SECURITY): every link to a section
     * (page.md#anchor, or #anchor in the same page) leads to an existing heading, with the anchors that Zensical
     * computes (Python-Markdown: without accents nor punctuation, words joined by hyphens).
     */
    /**
     * CHANGELOG and SECURITY are published at the root of the documentation site, where documentation/ does not exist:
     * a user page is linked by its address on the site, as the older entries do.
     */
    public function testTheChangelogAndTheSecurityPolicyLinkTheUserPagesByTheirAddress(): void
    {
        foreach (['CHANGELOG.md', 'CHANGELOG.en.md', 'SECURITY.md', 'SECURITY.en.md'] as $file) {
            self::assertDoesNotMatchRegularExpression('#\]\(documentation/#', (string) file_get_contents(__DIR__ . '/../../' . $file), $file);
        }
    }

    public function testAnchorsLeadToExistingSections(): void
    {
        $pages = [
            ...self::files('docs/*.md'), ...self::files('documentation/fr/*.md'), ...self::files('documentation/fr/utilisation/*.md'),
            ...self::files('documentation/en/*.md'), ...self::files('documentation/en/utilisation/*.md'),
            ...self::files('CHANGELOG*.md'), ...self::files('SECURITY*.md'),
        ];
        $pages = array_values(array_filter($pages, static fn(string $page): bool => !str_contains($page, '/exploitation/')));
        self::assertNotSame([], $pages);

        $broken = [];
        $checked = 0;
        foreach ($pages as $page) {
            foreach (self::links($page) as $target) {
                [$path, $anchor] = explode('#', $target, 2) + [1 => ''];
                if ($anchor === '' || str_contains($path, '://') || str_starts_with($path, 'mailto:')) {
                    continue;
                }
                $file = $path === '' ? $page : self::sitePage($page, $path);
                if ($file === null) {
                    continue;
                }
                ++$checked;
                if (!in_array(rawurldecode($anchor), self::anchors($file), true)) {
                    $broken[] = "$page → $target";
                }
            }
        }

        self::assertGreaterThan(50, $checked, 'links to sections checked');
        self::assertSame([], $broken, 'Liens vers une section absente (ancre de Zensical : sans accents ni ponctuation)');
    }

    /** Anchor of a heading, as Python-Markdown (Zensical) computes it. */
    public static function anchor(string $heading): string
    {
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading);
        $text = str_replace(['`', '*'], '', $text);
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KD);
        $text = is_string($normalized) ? $normalized : $text;
        $text = (string) preg_replace('/[^\x00-\x7F]/', '', $text);
        $text = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_\s-]/', '', $text)));

        return (string) preg_replace('/[-\s]+/', '-', $text);
    }

    public function testTheAnchorsAreThoseOfZensical(): void
    {
        self::assertSame('121-depuis-git', self::anchor('12.1 Depuis git'));
        self::assertSame('9-taches-planifiees-et-alertes', self::anchor('9. Tâches planifiées et alertes'));
        self::assertSame('3-configuration-envlocal', self::anchor('3. Configuration (`.env.local`)'));
        self::assertSame('navigateurs-de-confiance', self::anchor('Navigateurs de confiance'));
    }

    /** @return list<string> link targets of a page, outside the code blocks */
    private static function links(string $page): array
    {
        $content = (string) file_get_contents(self::ROOT . "/$page");
        $content = (string) preg_replace('/^```.*?^```/ms', '', $content);
        preg_match_all('/\]\(([^)\s]+)\)/', $content, $links);

        return $links[1];
    }

    /**
     * Page of the site that a link leads to, in the repository; null outside the site (README, code, other files).
     * In the site, docs/X.md (French) and docs/X.en.md (English) are published under documentation/<language>/exploitation/.
     */
    private static function sitePage(string $page, string $path): ?string
    {
        $resolved = self::normalize(dirname($page) . '/' . $path);
        if (preg_match('#^documentation/(fr|en)/exploitation/([^/]+)\.md$#', $resolved, $match) === 1) {
            $resolved = $match[1] === 'fr' ? "docs/$match[2].md" : "docs/$match[2].en.md";
        }
        $inSite = str_starts_with($resolved, 'docs/') || str_starts_with($resolved, 'documentation/')
            || preg_match('#^(CHANGELOG|SECURITY)(\.en)?\.md$#', $resolved) === 1;

        return $inSite && str_ends_with($resolved, '.md') && is_file(self::ROOT . "/$resolved") ? $resolved : null;
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /** @return list<string> anchors of the headings of a page (outside the code blocks) */
    private static function anchors(string $page): array
    {
        $content = (string) preg_replace('/^```.*?^```/ms', '', (string) file_get_contents(self::ROOT . "/$page"));
        preg_match_all('/^#{1,6}\s+(.+?)\s*#*\s*$/m', $content, $headings);

        return array_map(self::anchor(...), $headings[1]);
    }

    /** @return list<string> paths relative to the repository's root */
    private static function files(string $pattern): array
    {
        $files = glob(self::ROOT . "/$pattern");
        self::assertIsArray($files);

        return array_map(static fn(string $file): string => substr($file, strlen(self::ROOT) + 1), $files);
    }
}
