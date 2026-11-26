<?php

declare(strict_types=1);

namespace Patrimeter\Application\Carbure;

use Patrimeter\Application\ValidationFailed;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Carbure\CarbureClient;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\CarbureLinkRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Actual spending read from Carbure (self-hosted budget tracker: categorised bank transactions), against Patrimeter's
 * planned budget. Each user sets up their instance (address and API token); its top-level spending categories are
 * matched with those of the budget (by name, then by hand).
 *
 * Amounts: Carbure gives them in euros (decimal numbers); they are converted into cents as soon as they are read.
 * Safeguards: CARBURE_ALLOWED_HOSTS (allowed hosts, empty: all) and a "carbure" limiter per user.
 */
final class CarbureBudget
{
    /** @var list<string> */
    private readonly array $allowedHosts;

    public function __construct(
        private readonly CarbureLinkRepository $links,
        private readonly CarbureClient $client,
        private readonly BudgetRepository $budgets,
        private readonly RateLimiterFactoryInterface $carbureLimiter,
        #[Autowire('%env(CARBURE_ALLOWED_HOSTS)%')]
        string $allowedHosts,
    ) {
        $this->allowedHosts = array_values(array_filter(array_map(static fn(string $host): string => strtolower(trim($host)), explode(',', $allowedHosts)), static fn(string $host): bool => $host !== ''));
    }

    public function link(UserId $owner): ?CarbureLink
    {
        return $this->links->find($owner);
    }

    /**
     * Saves the user's instance after reading its spending categories (proof that the address and the token work).
     * Empty token: the one already saved. The categories with the same name as the budget's are matched with them;
     * the mapping already made is kept.
     *
     * @throws ValidationFailed  invalid address or token, refused host
     * @throws CarbureUnavailable
     */
    public function connect(UserId $owner, string $url, #[\SensitiveParameter] string $token): CarbureLink
    {
        $previous = $this->links->find($owner);
        $normalized = CarbureLink::normalizeUrl($url);
        $token = trim($token) === '' ? ($previous->token ?? '') : trim($token);
        $errors = [];
        if ($normalized === null) {
            $errors['url'] = 'Adresse invalide : http:// ou https:// suivi du nom de l\'instance, sans paramètres.';
        }
        if (!CarbureLink::isValidToken($token)) {
            $errors['token'] = 'Jeton invalide : collez le jeton d\'API créé dans Carbure (il commence par « cbt_ »).';
        }
        if ($normalized === null || $errors !== []) {
            throw new ValidationFailed($errors);
        }
        $link = new CarbureLink($normalized, $token, [], $previous->mapping ?? []);
        $this->guard($owner, $link);
        $categories = self::expenseCategories($this->client->call($link, 'list_categories'));
        $mapping = array_intersect_key($link->mapping, $categories);
        $byName = [];
        foreach ($this->budgets->categories($owner) as $category) {
            $byName[mb_strtolower(trim($category->name))] ??= $category->id;
        }
        foreach ($categories as $id => $name) {
            $mapping[$id] ??= $byName[mb_strtolower(trim($name))] ?? null;
        }
        $link = $link->with($categories, array_filter($mapping, static fn(?int $target): bool => $target !== null));
        $this->links->save($owner, $link);

        return $link;
    }

    /**
     * Mapping entered: Carbure category => budget category, "0" to ignore it, empty to leave it without a mapping.
     * Categories unknown on either side are dropped.
     *
     * @param array<mixed> $raw
     */
    public function saveMapping(UserId $owner, CarbureLink $link, array $raw): CarbureLink
    {
        $budget = array_map(static fn(BudgetCategory $category): int => $category->id, $this->budgets->categories($owner));
        $mapping = [];
        foreach (array_keys($link->categories) as $id) {
            $target = self::target($raw[$id] ?? null);
            if ($target !== null && ($target === 0 || in_array($target, $budget, true))) {
                $mapping[$id] = $target;
            }
        }
        $link = $link->with($link->categories, $mapping);
        $this->links->save($owner, $link);

        return $link;
    }

    public function disconnect(UserId $owner): void
    {
        $this->links->delete($owner);
    }

    /**
     * Actual spending of the month, per budget category.
     *
     * @throws CarbureUnavailable
     */
    public function actuals(UserId $owner, CarbureLink $link, Month $month): CarbureActuals
    {
        $this->guard($owner, $link);
        $rows = $this->client->call($link, 'get_budget', ['month' => $month->month, 'year' => $month->year, 'category_id' => 0]);
        $byCategory = [];
        $unmatched = [];
        foreach (array_keys($rows) as $key) {
            $row = self::row($rows[$key]);
            $id = self::integer($row['id'] ?? null);
            $cents = self::cents($row['consummed'] ?? null);
            if ($id === null || $cents === null || !isset($link->categories[$id])) {
                continue;
            }
            $target = $link->mapping[$id] ?? null;
            if ($target === null) {
                if ($cents !== 0) {
                    $unmatched[] = ['name' => $link->categories[$id], 'cents' => $cents];
                }
            } elseif ($target > 0) {
                $byCategory[$target] = ($byCategory[$target] ?? 0) + $cents;
            }
        }

        return new CarbureActuals($byCategory, $unmatched);
    }

    /** Host allowed by the administrator, and the user's limiter. */
    private function guard(UserId $owner, CarbureLink $link): void
    {
        if ($this->allowedHosts !== [] && !in_array($link->host(), $this->allowedHosts, true)) {
            throw new CarbureUnavailable(CarbureUnavailable::HOST_NOT_ALLOWED);
        }
        if (!$this->carbureLimiter->create($owner->value)->consume()->isAccepted()) {
            throw new CarbureUnavailable(CarbureUnavailable::THROTTLED);
        }
    }

    /**
     * Top-level spending categories (DEBIT type, without a parent), by identifier.
     *
     * @param array<mixed> $rows
     *
     * @return array<int, string>
     */
    private static function expenseCategories(array $rows): array
    {
        $categories = [];
        foreach (array_keys($rows) as $key) {
            $row = self::row($rows[$key]);
            $id = self::integer($row['id'] ?? null);
            $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '';
            $topLevel = !isset($row['parent']) || in_array($row['parent'], [false, '', 0], true);
            $expense = !isset($row['type']) || $row['type'] === 'DEBIT';
            if ($id !== null && $name !== '' && $topLevel && $expense) {
                $categories[$id] = mb_substr($name, 0, 60);
            }
        }

        return $categories;
    }

    /** @return array<mixed> */
    private static function row(mixed $row): array
    {
        return is_array($row) ? $row : [];
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /** Budget category chosen ("0": ignored); null if the field is empty or malformed. */
    private static function target(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) && strlen($value) <= 9 ? (int) $value : null;
    }

    /** Amount in euros read from Carbure (number or decimal text), in cents; null if unreadable. */
    private static function cents(mixed $euros): ?int
    {
        if (is_int($euros)) {
            $euros = (string) $euros;
        } elseif (is_float($euros) && is_finite($euros) && abs($euros) < 1e12) {
            $euros = number_format($euros, 2, '.', '');
        }
        if (!is_string($euros) || preg_match('/\A-?\d{1,12}(\.\d{1,2})?\z/', $euros) !== 1) {
            return null;
        }

        try {
            return Money::parse($euros, 'en');
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
