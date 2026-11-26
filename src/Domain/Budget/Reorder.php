<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

/**
 * Moving an element one place up or down among its siblings (categories of the same budget, items of the same
 * category), the other elements keeping their place in the whole order (pure).
 */
final class Reorder
{
    /**
     * @param list<int> $order    identifiers of all the elements, in their current order
     * @param list<int> $siblings identifiers among which $id moves, in the same order
     *
     * @return list<int>|null new order of all the elements, or null if $id cannot move that way
     */
    public static function move(array $order, array $siblings, int $id, int $offset): ?array
    {
        $index = array_search($id, $siblings, true);
        if ($index === false || !isset($siblings[$index + $offset]) || $offset === 0) {
            return null;
        }
        $moved = $siblings;
        [$moved[$index], $moved[$index + $offset]] = [$moved[$index + $offset], $moved[$index]];
        $next = 0;
        $result = [];
        foreach ($order as $element) {
            $result[] = in_array($element, $siblings, true) ? $moved[$next++] : $element;
        }

        return $result;
    }
}
