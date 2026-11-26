<?php

declare(strict_types=1);

namespace Patrimeter\Application\Maintenance;

use Patrimeter\Infrastructure\Supervision\LatestVersionCheck;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckLatestVersionHandler
{
    public function __construct(private readonly LatestVersionCheck $check) {}

    public function __invoke(CheckLatestVersion $message): void
    {
        $this->check->run();
    }
}
