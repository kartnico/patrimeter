<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit;

use Patrimeter\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testBuildsFromTheRuntimeContext(): void
    {
        $kernel = Kernel::fromRuntimeContext(['APP_ENV' => 'prod', 'APP_DEBUG' => '0']);
        self::assertSame('prod', $kernel->getEnvironment());
        self::assertFalse($kernel->isDebug());

        self::assertTrue(Kernel::fromRuntimeContext(['APP_ENV' => 'dev', 'APP_DEBUG' => true])->isDebug());
    }

    public function testRequiresAnEnvironment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Kernel::fromRuntimeContext(['APP_DEBUG' => '1']);
    }
}
