<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Patrimeter\Application\Family\FamilyPortfolio;
use Patrimeter\Application\Family\Perimeter;
use Patrimeter\Domain\UserId;
use Symfony\Component\HttpFoundation\Request;

/**
 * Perimeter chosen in a family account (FamilyPortfolio): the one asked for by the "perimetre" parameter, kept in the
 * session for the next pages (dashboard, history, report, budget); otherwise the one kept; otherwise the family. An
 * account without members only shows its own data, without a choice.
 */
final class PerimeterSelection
{
    public const string QUERY = 'perimetre';
    private const string SESSION = 'patrimeter_perimeter';

    public function __construct(private readonly FamilyPortfolio $family) {}

    /**
     * @return array{perimeter: Perimeter, choices: list<array{key: string, name: ?string, current: bool}>}
     *               choices offered: name of the member, or null for the family ("famille" key) and this account ("compte")
     */
    public function resolve(Request $request, UserId $owner): array
    {
        $choices = $this->family->perimeters($owner, $this->family->members($owner));
        if ($choices === []) {
            return ['perimeter' => Perimeter::own(), 'choices' => []];
        }
        $valid = [];
        foreach ($choices as $choice) {
            $valid[$choice['perimeter']->key] = $choice['perimeter'];
        }
        $session = $request->hasSession() ? $request->getSession() : null;
        $asked = $request->query->getString(self::QUERY);
        if (isset($valid[$asked])) {
            $session?->set(self::SESSION, $asked);
            $key = $asked;
        } else {
            $key = self::kept($session?->get(self::SESSION), array_keys($valid));
        }

        return [
            'perimeter' => $valid[$key],
            'choices' => array_map(static fn(array $choice): array => [
                'key' => $choice['perimeter']->key,
                'name' => $choice['name'],
                'current' => $choice['perimeter']->key === $key,
            ], $choices),
        ];
    }

    /**
     * Perimeter kept in the session, if it is still offered; otherwise the family.
     *
     * @param list<string> $valid
     */
    private static function kept(mixed $value, array $valid): string
    {
        return is_string($value) && in_array($value, $valid, true) ? $value : Perimeter::FAMILY;
    }
}
