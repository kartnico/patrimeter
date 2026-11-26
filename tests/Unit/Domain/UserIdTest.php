<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\UserId;
use PHPUnit\Framework\TestCase;

final class UserIdTest extends TestCase
{
    public function testAcceptsCanonicalUuidsOnly(): void
    {
        $id = UserId::fromString('01900000-0000-7000-8000-000000000001');

        self::assertSame('01900000-0000-7000-8000-000000000001', $id->value);
        self::assertTrue($id->equals(UserId::fromString('01900000-0000-7000-8000-000000000001')));
        self::assertFalse($id->equals(UserId::fromString('01900000-0000-7000-8000-000000000002')));

        $this->expectException(\InvalidArgumentException::class);
        UserId::fromString('01900000-0000-7000-8000-00000000000A');
    }
}
