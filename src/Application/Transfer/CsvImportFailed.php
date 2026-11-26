<?php

declare(strict_types=1);

namespace Patrimeter\Application\Transfer;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * CSV import refused, nothing imported: the line of the file concerned (0: the whole file) and the reasons, each one
 * translated on display.
 */
final class CsvImportFailed extends \DomainException
{
    /** @param list<string|TranslatableInterface> $reasons */
    public function __construct(public readonly int $fileLine, public readonly array $reasons)
    {
        parent::__construct('CSV import refused');
    }
}
