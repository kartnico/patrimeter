<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;
use Patrimeter\Tests\Support\ApiTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The OpenAPI description (docs/openapi.yaml) tells the truth: it covers exactly the API's routes, and every real
 * answer, success as well as error, carries a documented status and a body that matches the described schema.
 * Validator deliberately limited to the keywords the description uses.
 */
final class OpenApiTest extends ApiTestCase
{
    private const string PREFIX = '/api/v1';

    /** @var array<array-key, mixed> */
    private array $spec;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $spec = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        self::assertIsArray($spec);
        $this->spec = $spec;
    }

    public function testTheDescriptionCoversExactlyTheApiRoutes(): void
    {
        $actual = [];
        foreach (self::service(RouterInterface::class)->getRouteCollection() as $route) {
            if (!str_starts_with($route->getPath(), self::PREFIX . '/')) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $actual[] = $method . ' ' . substr($route->getPath(), strlen(self::PREFIX));
            }
        }

        $described = [];
        foreach (self::node($this->spec['paths'] ?? null) as $path => $operations) {
            foreach (array_keys(self::node($operations)) as $method) {
                if ($method !== 'parameters') {
                    $described[] = strtoupper((string) $method) . ' ' . $path;
                }
            }
        }

        sort($actual);
        sort($described);
        self::assertSame($actual, $described, 'docs/openapi.yaml décrit exactement les routes /api/v1');
    }

    public function testEveryResponseMatchesItsDescription(): void
    {
        $write = $this->personalToken(write: true);
        $read = $this->personalToken();
        $house = self::createAccount('Maison', 'lmnp', '2024-01-01');
        $loan = self::createAccount('Prêt', 'credit_immo_rp', '2024-01-01');
        $livretType = self::typeId('livret_a');
        $budget = self::createBudget(self::owner(), 'Camille', 'Logement', 'Loyer', 100_000, '2026-11');
        self::service(BudgetRepository::class)->createLine(self::owner(), $budget['category'], 'Épargne maison', null, $house);
        self::service(SavingsGoalRepository::class)->create(self::owner(), new SavingsGoal(0, 'Maison', 40_000_000, Month::fromString('2028-01'), GoalScope::Accounts, null, [$house], Month::fromString('2026-10'), 30_000_000));
        self::service(SavingsGoalRepository::class)->create(self::owner(), new SavingsGoal(0, 'Précaution', 1_000_000, Month::fromString('2027-06'), GoalScope::Level, Level::Precaution, [], Month::fromString('2026-11'), 0, 6));

        $calls = [
            ['PUT', "/accounts/$house/shares/2025-01", $write, ['share_millionths' => 500_000], 204],
            ['PUT', "/accounts/$loan/loan-link", $write, ['asset_id' => $house, 'loan_mode' => 'prorata'], 204],
            ['PUT', "/accounts/$house/statements/2026-10", $write, ['amount_cents' => 30_000_000], 204],
            ['PUT', "/accounts/$house/statements/2026-11", $write, ['amount_cents' => 31_000_000], 204],
            ['PUT', "/accounts/$loan/statements/2026-11", $write, ['amount_cents' => 12_000_000], 204],
            ['POST', '/accounts', $write, ['name' => 'Livret A', 'type_id' => $livretType, 'opened_on' => '2025-01-01', 'initial_amount_cents' => 2_295_000], 201],
            ['GET', '/me', $read, null, 200],
            ['GET', '/account-types', $read, null, 200],
            ['GET', '/net-worth?month=2026-11', $read, null, 200],
            ['GET', '/history?from=2026-10&to=2026-11', $read, null, 200],
            ['GET', '/properties?month=2026-11', $read, null, 200],
            ['GET', '/goals?month=2026-11', $read, null, 200],
            ['GET', '/budget?month=2026-11', $read, null, 200],
            ['GET', '/accounts', $read, null, 200],
            ['GET', "/accounts/$house", $read, null, 200],
            ['GET', "/accounts/$house/statements", $read, null, 200],
            ['GET', "/accounts/$house/shares", $read, null, 200],
            ['PUT', "/accounts/$house", $write, ['name' => 'Maison de Nantes', 'type_id' => self::typeId('lmnp'), 'opened_on' => '2024-01-01'], 200],
            ['POST', "/accounts/$loan/archive", $write, ['closed_on' => '2026-12-31'], 200],
            ['POST', "/accounts/$loan/unarchive", $write, null, 200],
            // Errors: missing token, read only, unknown account, refused data.
            ['GET', '/accounts', null, null, 401],
            ['POST', '/accounts', $read, ['name' => 'X', 'type_id' => $livretType, 'opened_on' => '2025-01-01'], 403],
            ['GET', '/accounts/999999', $read, null, 404],
            ['PUT', "/accounts/$house/statements/2026-11", $write, ['amount_cents' => 1.5], 422],
            ['GET', '/net-worth?month=2026-13', $read, null, 422],
            ['DELETE', "/accounts/$house/statements/2026-10", $write, null, 204],
            ['DELETE', "/accounts/$house/shares/2025-01", $write, null, 204],
            ['DELETE', "/accounts/$loan", $write, null, 204],
        ];

        foreach ($calls as [$method, $path, $token, $body, $expected]) {
            $response = $this->api($method, self::PREFIX . $path, $token, $body);
            $label = "$method $path";
            self::assertSame($expected, $response->getStatusCode(), $label . ' : ' . $response->getContent());

            $documented = $this->response($method, $path, $expected);
            $content = (string) $response->getContent();
            if ($content === '') {
                self::assertArrayNotHasKey('content', $documented, "$label : corps vide documenté");
                continue;
            }
            $type = strtok((string) $response->headers->get('Content-Type'), ';');
            $schema = self::node(self::node(self::node($documented['content'] ?? null)[$type] ?? null, "$label : type $type non documenté")['schema'] ?? null);
            $errors = [];
            $this->validate(json_decode($content, true, flags: JSON_THROW_ON_ERROR), $schema, '$', $errors);
            self::assertSame([], $errors, "$label : réponse non conforme à docs/openapi.yaml");
        }
    }

    public function testTheEnglishDescriptionOnlyTranslatesTheTexts(): void
    {
        $english = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi.en.yaml');
        self::assertIsArray($english);

        self::assertSame(
            self::withoutTexts($this->spec),
            self::withoutTexts($english),
            'docs/openapi.en.yaml traduit docs/openapi.yaml sans rien y changer d\'autre : mêmes routes, paramètres, schémas et exemples',
        );
    }

    public function testTheValidatorCatchesADifferentShape(): void
    {
        $errors = [];
        $this->validate(['month' => '2026-13', 'has_data' => 'oui'], ['$ref' => '#/components/schemas/NetWorth'], '$', $errors);

        self::assertContains('$.month : motif', $errors);
        self::assertContains('$.has_data : type boolean attendu', $errors);
        self::assertContains('$ : net_cents manquant', $errors);
    }

    /**
     * Documented answer for this status ($ref resolved).
     *
     * @return array<array-key, mixed>
     */
    private function response(string $method, string $path, int $status): array
    {
        $template = (string) preg_replace(['#\?.*$#', '#/accounts/\d+#', '#/\d{4}-\d{2}$#'], ['', '/accounts/{id}', '/{month}'], $path);
        $operation = self::node(self::node(self::node($this->spec['paths'] ?? null)[$template] ?? null, "$template non décrit")[strtolower($method)] ?? null, "$method $template non décrit");
        $responses = self::node($operation['responses'] ?? null);

        return $this->resolve(self::node($responses[$status] ?? null, "$method $template : statut $status non documenté"));
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private function resolve(array $node): array
    {
        while (isset($node['$ref']) && is_string($node['$ref'])) {
            $target = $this->spec;
            foreach (array_slice(explode('/', $node['$ref']), 1) as $part) {
                $target = self::node($target[$part] ?? null, 'référence introuvable : ' . $node['$ref']);
            }
            $node = $target;
        }

        return $node;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param list<string>         $errors
     */
    private function validate(mixed $value, array $schema, string $at, array &$errors): void
    {
        $schema = $this->resolve($schema);
        if (isset($schema['allOf'])) {
            // Object in several parts: a single list of properties and of required fields.
            $required = [];
            $properties = [];
            foreach (self::items($schema['allOf']) as $part) {
                $part = $this->resolve(self::node($part));
                $required = [...$required, ...self::items($part['required'] ?? [])];
                $properties += self::node($part['properties'] ?? []);
            }
            $schema = ['type' => 'object', 'required' => $required, 'properties' => $properties];
        }
        if (isset($schema['oneOf'])) {
            $matches = 0;
            foreach (self::items($schema['oneOf']) as $part) {
                $partErrors = [];
                $this->validate($value, self::node($part), $at, $partErrors);
                $matches += $partErrors === [] ? 1 : 0;
            }
            if ($matches !== 1) {
                $errors[] = "$at : $matches variantes oneOf correspondent";
            }
        }
        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? array_values($schema['type']) : [$schema['type']];
            $type = self::typeOf($value);
            $accepted = in_array($type, $types, true)
                || $type === 'integer' && in_array('number', $types, true)
                || $value === [] && in_array('array', $types, true);
            if (!$accepted) {
                $errors[] = "$at : type " . implode('|', array_filter($types, is_string(...))) . ' attendu';

                return;
            }
        }
        if (isset($schema['enum']) && !in_array($value, self::items($schema['enum']), true)) {
            $errors[] = "$at : valeur hors de l'énumération";
        }
        if (is_string($value)) {
            $pattern = $schema['pattern'] ?? null;
            if (is_string($pattern) && preg_match('/' . $pattern . '/', $value) !== 1) {
                $errors[] = "$at : motif";
            }
            $format = is_string($schema['format'] ?? null) ? $schema['format'] : '';
            if ($format === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || $format === 'uuid' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) !== 1) {
                $errors[] = "$at : format $format";
            }
        }
        if (is_int($value)) {
            $minimum = $schema['minimum'] ?? null;
            $maximum = $schema['maximum'] ?? null;
            if (is_int($minimum) && $value < $minimum || is_int($maximum) && $value > $maximum) {
                $errors[] = "$at : hors bornes";
            }
        }
        if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                $this->validate($item, self::node($schema['items']), "{$at}[$index]", $errors);
            }
        }
        if (is_array($value) && self::typeOf($value) === 'object') {
            foreach (self::items($schema['required'] ?? []) as $field) {
                if (is_string($field) && !array_key_exists($field, $value)) {
                    $errors[] = "$at : $field manquant";
                }
            }
            $properties = self::node($schema['properties'] ?? []);
            foreach ($value as $field => $item) {
                if (isset($properties[$field])) {
                    $this->validate($item, self::node($properties[$field]), "$at.$field", $errors);
                } elseif (isset($schema['additionalProperties'])) {
                    $this->validate($item, self::node($schema['additionalProperties']), "$at.$field", $errors);
                } elseif (isset($schema['properties'])) {
                    $errors[] = "$at.$field : propriété non décrite";
                }
            }
        }
    }

    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && array_is_list($value) && $value !== [] => 'array',
            default => 'object',
        };
    }

    /**
     * Description stripped of its texts, the only translated part: title, summaries, descriptions (OAuth scopes
     * included) removed, name of each tag replaced by its rank in the list of tags, where it is declared as well as
     * where it is used.
     *
     * @param array<array-key, mixed> $spec
     *
     * @return array<array-key, mixed>
     */
    private static function withoutTexts(array $spec): array
    {
        $ranks = [];
        $tags = [];
        foreach (self::items($spec['tags'] ?? []) as $rank => $tag) {
            $tag = self::node($tag);
            self::assertIsString($tag['name'] ?? null);
            $ranks[$tag['name']] = $rank;
            $tags[] = ['name' => $rank] + $tag;
        }
        $spec['tags'] = $tags;

        return self::stripTexts($spec, $ranks);
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, int>      $ranks
     *
     * @return array<array-key, mixed>
     */
    private static function stripTexts(array $node, array $ranks): array
    {
        $stripped = [];
        foreach ($node as $key => $value) {
            if (in_array($key, ['title', 'summary', 'description'], true) && is_string($value)) {
                continue;
            }
            if ($key === 'tags' && is_array($value) && array_is_list($value)) {
                $value = array_map(static fn(mixed $tag): mixed => is_string($tag) ? ($ranks[$tag] ?? $tag) : $tag, $value);
            }
            if ($key === 'scopes' && is_array($value) && !array_is_list($value)) {
                // Scopes of an OAuth flow: name (identical) => description (translated).
                $value = array_fill_keys(array_keys($value), '');
            }
            $stripped[$key] = is_array($value) ? self::stripTexts($value, $ranks) : $value;
        }

        return $stripped;
    }

    /**
     * Node of the description (YAML object). The statuses ("200") are integer keys there, as always in PHP.
     *
     * @return array<array-key, mixed>
     */
    private static function node(mixed $value, string $message = 'objet attendu dans docs/openapi.yaml'): array
    {
        self::assertIsArray($value, $message);

        return $value;
    }

    /** @return list<mixed> */
    private static function items(mixed $value): array
    {
        self::assertIsArray($value, 'liste attendue dans docs/openapi.yaml');

        return array_values($value);
    }

    private static function typeId(string $code): int
    {
        $id = self::service(Connection::class)->fetchOne('SELECT id FROM account_types WHERE code = ?', [$code]);
        self::assertIsNumeric($id, "type de compte inconnu : $code");

        return (int) $id;
    }
}
