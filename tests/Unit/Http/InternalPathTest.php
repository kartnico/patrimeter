<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Http;

use Patrimeter\Http\InternalPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InternalPathTest extends TestCase
{
    /** @return iterable<string, array{string|null, string}> */
    public static function paths(): iterable
    {
        yield 'chemin simple' => ['/comptes', '/comptes'];
        yield 'avec paramètres' => ['/historique?periode=tout&vue=niveaux', '/historique?periode=tout&vue=niveaux'];
        yield 'racine' => ['/', '/'];
        yield 'absent' => [null, '/'];
        yield 'vide' => ['', '/'];
        yield 'URL absolue' => ['https://evil.example/', '/'];
        yield 'relatif au protocole' => ['//evil.example', '/'];
        yield 'antislash' => ['/\\evil.example', '/'];
        yield 'relatif' => ['comptes', '/'];
        yield 'saut de ligne' => ["/comptes\nSet-Cookie: x", '/'];
        yield 'caractère nul' => ["/comptes\0", '/'];
    }

    #[DataProvider('paths')]
    public function testSanitize(?string $path, string $expected): void
    {
        self::assertSame($expected, InternalPath::sanitize($path));
    }
}
