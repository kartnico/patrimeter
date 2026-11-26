<?php

declare(strict_types=1);

namespace Patrimeter;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Kernel built from the context given by symfony/runtime (APP_ENV, APP_DEBUG).
     *
     * @param array<mixed> $context
     */
    public static function fromRuntimeContext(array $context): self
    {
        $environment = $context['APP_ENV'] ?? null;
        if (!is_string($environment) || $environment === '') {
            throw new \InvalidArgumentException('La variable APP_ENV doit être définie.');
        }

        return new self($environment, filter_var($context['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL));
    }
}
