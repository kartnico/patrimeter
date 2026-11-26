<?php

declare(strict_types=1);

namespace Patrimeter\Tools\PHPStan;

use Doctrine\DBAL\Connection;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * SQL is confined to Patrimeter\Infrastructure\Persistence: elsewhere in the application, no query can be sent to
 * the database (the isolation between users relies on the repositories, which all filter by owner). Transactions
 * remain allowed.
 *
 * @implements Rule<MethodCall>
 */
final class NoSqlOutsidePersistenceRule implements Rule
{
    private const string CHECKED_NAMESPACE = 'Patrimeter\\';
    private const array ALLOWED_NAMESPACES = ['Patrimeter\\Infrastructure\\Persistence\\', 'Patrimeter\\Tests\\', 'Patrimeter\\Tools\\'];
    private const array SQL_METHODS = [
        'executestatement', 'executequery', 'executecachequery', 'prepare', 'exec',
        'fetchone', 'fetchassociative', 'fetchnumeric', 'fetchallassociative', 'fetchallnumeric',
        'fetchallkeyvalue', 'fetchallassociativeindexed', 'fetchfirstcolumn',
        'iteratenumeric', 'iterateassociative', 'iteratekeyvalue', 'iterateassociativeindexed', 'iteratecolumn',
        'insert', 'update', 'delete', 'createquerybuilder',
    ];

    #[\Override]
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /** @return list<IdentifierRuleError> */
    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $namespace = ($scope->getNamespace() ?? '') . '\\';
        if (!str_starts_with($namespace, self::CHECKED_NAMESPACE) || self::isAllowed($namespace)) {
            return [];
        }
        if (!$node->name instanceof Identifier || !in_array($node->name->toLowerString(), self::SQL_METHODS, true)) {
            return [];
        }
        if (!(new ObjectType(Connection::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Requête SQL (%s()) hors de Patrimeter\Infrastructure\Persistence : passer par un dépôt.',
                $node->name->toString(),
            ))->identifier('patrimeter.sqlOutsidePersistence')->build(),
        ];
    }

    private static function isAllowed(string $namespace): bool
    {
        foreach (self::ALLOWED_NAMESPACES as $allowed) {
            if (str_starts_with($namespace, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
