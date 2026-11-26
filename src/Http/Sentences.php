<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Message made of several sentences translated separately, then joined (for instance "Trop de tentatives."
 * followed by "Réessayez dans 5 minutes."): it is translated on display, like a TranslatableMessage (Twig trans
 * filter, flash messages).
 */
final readonly class Sentences implements TranslatableInterface
{
    /** @var list<string|TranslatableInterface> */
    private array $parts;

    public function __construct(string|TranslatableInterface ...$parts)
    {
        $this->parts = array_values($parts);
    }

    /** Sentences joined, without the empty parts. */
    public static function of(string|TranslatableInterface|null ...$parts): self
    {
        return new self(...array_values(array_filter($parts, static fn(string|TranslatableInterface|null $part): bool => $part !== null && $part !== '')));
    }

    #[\Override]
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return implode(' ', array_map(
            static fn(string|TranslatableInterface $part): string => is_string($part) ? $translator->trans($part, [], null, $locale) : $part->trans($translator, $locale),
            $this->parts,
        ));
    }
}
