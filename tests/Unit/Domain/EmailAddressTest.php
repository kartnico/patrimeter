<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\EmailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testNormalizesCaseAndSurroundingSpaces(): void
    {
        self::assertSame('marie.dupont+patrimoine@example.fr', EmailAddress::normalize('  Marie.Dupont+Patrimoine@EXAMPLE.fr '));
        self::assertSame('a@b', EmailAddress::normalize('a@b'), 'règle des navigateurs : domaine sans point accepté');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAddresses(): iterable
    {
        yield 'vide' => [''];
        yield 'sans arobase' => ['marie.example.fr'];
        yield 'deux arobases' => ['marie@dupont@example.fr'];
        yield 'espace' => ['marie dupont@example.fr'];
        yield 'domaine vide' => ['marie@'];
        yield 'tiret en tête de domaine' => ['marie@-example.fr'];
        yield 'accent' => ['élodie@example.fr'];
        yield 'points doublés' => ['a..b@example.com'];
        yield 'point en tête' => ['.a@example.com'];
        yield 'point en fin de partie locale' => ['a.@example.com'];
        yield 'trop longue' => [str_repeat('a', 245) . '@example.fr'];
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsInvalidAddresses(string $input): void
    {
        self::assertNull(EmailAddress::normalize($input));
    }

    /** Any accepted address must be able to receive an email: the mailer must never refuse it. */
    public function testEveryAcceptedAddressIsAcceptedByTheMailer(): void
    {
        foreach (['a@b', 'a.b.c@example.fr', "a'b@x.fr", 'a!#$%&*+/=?^_`{|}~-z@x.fr', str_repeat('a', 64) . '@x.fr', 'a@xn--bcher-kva.de'] as $address) {
            $normalized = EmailAddress::normalize($address);
            self::assertNotNull($normalized, $address);
            self::assertSame($normalized, (new \Symfony\Component\Mime\Address($normalized))->getAddress());
        }
    }
}
