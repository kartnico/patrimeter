<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Game;

use Patrimeter\Domain\Month;

/**
 * Level reached with the stars earned (the best of each challenge, never lost). Six levels, from 0 stars up to the most
 * every challenge can give together (maxStars()).
 */
final class SaverLevel
{
    /** Stars needed for each level, and its name (French source string). */
    private const array LEVELS = [
        0 => 'Curieux',
        4 => 'Attentif',
        8 => 'Régulier',
        13 => 'Prévoyant',
        18 => 'Constant',
        24 => 'Maître de sa pyramide',
    ];

    private function __construct(
        public readonly int $number,
        public readonly string $name,
        public readonly int $stars,
        public readonly int $from,
        public readonly ?int $next,
    ) {}

    public static function of(int $stars): self
    {
        $number = 0;
        $from = 0;
        $name = '';
        $next = null;
        foreach (self::LEVELS as $threshold => $label) {
            if ($stars < $threshold) {
                $next = $threshold;
                break;
            }
            ++$number;
            $from = $threshold;
            $name = $label;
        }

        return new self($number, $name, $stars, $from, $next);
    }

    /** Most stars that can be earned together. */
    /**
     * Every level, from the first: its number, its name and the stars it needs.
     *
     * @return list<array{number: int, name: string, from: int}>
     */
    public static function ladder(): array
    {
        $ladder = [];
        $number = 0;
        foreach (self::LEVELS as $from => $name) {
            $ladder[] = ['number' => ++$number, 'name' => $name, 'from' => $from];
        }

        return $ladder;
    }

    public static function maxStars(): int
    {
        return array_sum(array_map(static fn(Challenge $challenge): int => $challenge->evaluate(new GameFacts(Month::fromString('2000-01')))->maxStars(), Challenge::cases()));
    }

    public static function count(): int
    {
        return count(self::LEVELS);
    }

    /** Progress towards the next level, from the start of this one: 0 to 100. */
    public function percent(): int
    {
        if ($this->next === null) {
            return 100;
        }

        return intdiv(100 * ($this->stars - $this->from), $this->next - $this->from);
    }

    /** Name of the next level (French source string), or null at the last level. */
    public function nextName(): ?string
    {
        return $this->next === null ? null : (self::LEVELS[$this->next] ?? null);
    }

    /** Stars still needed for the next level, or null at the last level. */
    public function missing(): ?int
    {
        return $this->next === null ? null : $this->next - $this->stars;
    }
}
