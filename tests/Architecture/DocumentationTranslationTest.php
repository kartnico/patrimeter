<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bilingual documentation: each page exists in French, which is the reference, and in English, and the translation
 * follows the French page. Same sections (heading levels), same code blocks and same screenshots; the blocks run by
 * the CI (<!-- ci:… -->, "deployment" job) carry the same commands, only their comments change. The technical
 * development pages only exist in French (list of documentation/prepare.sh).
 */
final class DocumentationTranslationTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    /** Pages at the repository's root, translated next to the original (X.md → X.en.md). */
    private const array ROOT_PAGES = ['README', 'CHANGELOG', 'SECURITY'];

    public function testEverySitePageHasItsTranslation(): void
    {
        self::assertNotSame([], self::sitePages('fr'));
        self::assertSame(self::sitePages('fr'), self::sitePages('en'), 'documentation/en/ traduit chaque page de documentation/fr/');
    }

    public function testEveryTechnicalPageHasItsTranslationExceptTheFrenchOnlyOnes(): void
    {
        $french = [];
        $english = [];
        foreach (self::files('docs/*.md') as $page) {
            if (str_ends_with($page, '.en.md')) {
                $english[] = basename($page, '.en.md');
            } else {
                $french[] = basename($page, '.md');
            }
        }
        $frenchOnly = self::frenchOnlyPages();

        self::assertSame([], array_values(array_diff($frenchOnly, $french)), 'Pages en français seulement (prepare.sh) inexistantes');
        self::assertSame(array_values(array_diff($french, $frenchOnly)), $english, 'Chaque page de docs/ a sa traduction docs/<page>.en.md, sauf celles que prepare.sh déclare en français seulement');
        foreach (self::ROOT_PAGES as $name) {
            self::assertFileExists(self::ROOT . "/$name.en.md");
        }
    }

    public function testBothSitesHaveTheSameNavigation(): void
    {
        $french = self::navigation('documentation/zensical.toml');
        self::assertNotSame([], $french);
        self::assertSame($french, self::navigation('documentation/zensical.en.toml'), 'Mêmes pages, dans le même ordre, dans les deux sites');
    }

    /** @return iterable<string, array{string, string}> */
    public static function translations(): iterable
    {
        foreach (self::sitePages('fr') as $page) {
            yield "documentation/$page" => ["documentation/fr/$page", "documentation/en/$page"];
        }
        foreach (self::files('docs/*.en.md') as $page) {
            $name = basename($page, '.en.md');
            yield "docs/$name.md" => ["docs/$name.md", "docs/$name.en.md"];
        }
        foreach (self::ROOT_PAGES as $name) {
            yield "$name.md" => ["$name.md", "$name.en.md"];
        }
    }

    #[DataProvider('translations')]
    public function testTheTranslationFollowsTheFrenchPage(string $french, string $english): void
    {
        $original = self::structure($french);
        $translation = self::structure($english);

        self::assertSame($original['headings'], $translation['headings'], "$english : mêmes sections (niveaux de titres) que $french");
        self::assertSame($original['code'], $translation['code'], "$english : mêmes blocs de code que $french");
        self::assertSame($original['images'], $translation['images'], "$english : mêmes captures d'écran que $french");
        self::assertSame($original['ci'], $translation['ci'], "$english : mêmes commandes exécutées par la CI que $french (seuls les commentaires se traduisent)");
    }

    #[DataProvider('translations')]
    public function testTheTranslationLinksToTranslatedPages(string $french, string $english): void
    {
        $directory = dirname(self::ROOT . "/$english");
        $wrong = [];
        foreach (self::links($english) as $target) {
            // The only link allowed to the French version of a translated page: the page's link to its original (README).
            if (preg_match('/^(.+?)(?<!\.en)\.(md|yaml)$/', $target, $match) === 1 && is_file("$directory/$match[1].en.$match[2]")
                && basename($target) !== basename($french)) {
                $wrong[] = $target;
            }
        }
        foreach (self::links($french) as $target) {
            // The only link allowed to the English version: the page's link to its own translation (README).
            if (preg_match('/\.en\.(md|yaml)$/', $target) === 1 && basename($target) !== basename($english)) {
                $wrong[] = $target;
            }
        }

        self::assertSame([], $wrong, "$english renvoie à la traduction des pages qui en ont une (X.en.md), et $french aux originaux");
    }

    public function testBothChangelogsListTheSameVersions(): void
    {
        $versions = static function (string $file): array {
            preg_match_all('/^## \[(\d+\.\d+\.\d+)\] - (\d{4}-\d{2}-\d{2})$/m', self::read($file), $matches, PREG_SET_ORDER);

            return array_map(static fn(array $match): string => "$match[1] ($match[2])", $matches);
        };

        self::assertNotSame([], $versions('CHANGELOG.md'));
        self::assertSame($versions('CHANGELOG.md'), $versions('CHANGELOG.en.md'), 'CHANGELOG.en.md traduit chaque version de CHANGELOG.md');
    }

    public function testEachReadmeLinksToTheOtherLanguage(): void
    {
        self::assertStringContainsString('[English](README.en.md)', self::read('README.md'));
        self::assertStringContainsString('[Français](README.md)', self::read('README.en.md'));
    }

    /**
     * Comparable structure of a Markdown page: heading levels, language of each code block, local images
     * (screenshots), commands of the blocks run by the CI, without their comments.
     *
     * @return array{headings: list<int>, code: list<string>, images: list<string>, ci: list<array{string, list<string>}>}
     */
    private static function structure(string $file): array
    {
        $headings = [];
        $code = [];
        $ci = [];
        $fence = null;
        $marker = null;
        $commands = null;
        foreach (explode("\n", self::read($file)) as $line) {
            if (preg_match('/^\s*(`{3,})(\S*)\s*$/', $line, $match) === 1) {
                if ($fence === null) {
                    $fence = $match[1];
                    $code[] = $match[2];
                    $commands = $marker !== null && $match[2] === 'sh' ? [] : null;
                } elseif ($match[1] === $fence && $match[2] === '') {
                    if ($marker !== null && $commands !== null) {
                        $ci[] = [$marker, $commands];
                    }
                    $fence = null;
                    $marker = null;
                    $commands = null;
                }

                continue;
            }
            if ($fence !== null) {
                if ($commands !== null) {
                    $command = rtrim((string) preg_replace('/\s+#\s.*$/', '', $line));
                    if ($command !== '' && !str_starts_with(ltrim($command), '#')) {
                        $commands[] = $command;
                    }
                }

                continue;
            }
            if (preg_match('/^<!-- (ci:[a-z-]+) -->$/', $line, $match) === 1) {
                $marker = $match[1];
            } elseif (preg_match('/^(#{1,6}) /', $line, $match) === 1) {
                $headings[] = strlen($match[1]);
            }
        }

        preg_match_all('/!\[[^\]]*\]\(([^)\s]+)\)/', self::read($file), $images);
        $local = array_values(array_filter($images[1], static fn(string $target): bool => !str_contains($target, '://')));

        return ['headings' => $headings, 'code' => $code, 'images' => $local, 'ci' => $ci];
    }

    /** @return list<string> relative targets of a page's Markdown links, without anchor */
    private static function links(string $file): array
    {
        preg_match_all('/\]\(([^)\s#]+)(?:#[^)\s]*)?\)/', self::read($file), $matches);

        return array_values(array_filter($matches[1], static fn(string $target): bool => !str_contains($target, '://')));
    }

    /** @return list<string> Markdown pages of a site, sorted relative paths (parts generated by prepare.sh excluded) */
    private static function sitePages(string $language): array
    {
        $base = self::ROOT . "/documentation/$language";
        $pages = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'md') {
                continue;
            }
            $page = substr($file->getPathname(), strlen($base) + 1);
            if (!str_starts_with($page, 'exploitation/') && !in_array($page, ['CHANGELOG.md', 'SECURITY.md'], true)) {
                $pages[] = $page;
            }
        }
        sort($pages);

        return $pages;
    }

    /** @return list<string> technical pages in French only, declared in documentation/prepare.sh */
    private static function frenchOnlyPages(): array
    {
        self::assertSame(1, preg_match("/^french_only='([a-z -]+)'$/m", self::read('documentation/prepare.sh'), $match));
        $pages = explode(' ', $match[1]);
        sort($pages);

        return $pages;
    }

    /** @return list<string> pages of the navigation of a Zensical configuration, in order */
    private static function navigation(string $config): array
    {
        preg_match_all('/"([a-z\/-]+\.md|[A-Z]+\.md)"/', self::read($config), $matches);

        return $matches[1];
    }

    /** @return list<string> files of the repository matching the pattern, sorted */
    private static function files(string $pattern): array
    {
        $files = glob(self::ROOT . "/$pattern");
        self::assertIsArray($files);
        sort($files);

        return $files;
    }

    private static function read(string $file): string
    {
        $content = file_get_contents(self::ROOT . "/$file");
        self::assertIsString($content, "$file illisible");

        return $content;
    }
}
