<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Isolation, checked on the code: every SQL query of the wealth data repositories filters or writes the owner
 * (`user_id`). A query added without the owner makes this test fail before it is even run.
 */
final class OwnedDataQueriesTest extends TestCase
{
    private const array OWNED_DATA_REPOSITORIES = [
        __DIR__ . '/../../src/Infrastructure/Persistence/AccountRepository.php',
        __DIR__ . '/../../src/Infrastructure/Persistence/StatementRepository.php',
        __DIR__ . '/../../src/Infrastructure/Persistence/OwnershipShareRepository.php',
        __DIR__ . '/../../src/Infrastructure/Persistence/BudgetRepository.php',
        __DIR__ . '/../../src/Infrastructure/Persistence/SavingsGoalRepository.php',
        __DIR__ . '/../../src/Infrastructure/Persistence/CustomIndicatorRepository.php',
        __DIR__ . '/../../src/Infrastructure/Persistence/GameRepository.php',
    ];

    /** @return iterable<string, array{string}> */
    public static function repositories(): iterable
    {
        foreach (self::OWNED_DATA_REPOSITORIES as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('repositories')]
    public function testEverySqlStatementMentionsTheOwner(string $file): void
    {
        $statements = self::sqlStatements($file);

        self::assertNotEmpty($statements);
        foreach ($statements as $sql) {
            self::assertStringContainsString('user_id', $sql, "Requête sans propriétaire dans $file :\n$sql");
        }
    }

    /** @return list<string> strings of the file that start with an SQL statement */
    private static function sqlStatements(string $file): array
    {
        $code = file_get_contents($file);
        self::assertIsString($code);
        $statements = [];
        $heredoc = null;
        foreach (token_get_all($code) as $token) {
            if (!is_array($token)) {
                continue;
            }
            [$id, $text] = $token;
            if ($id === T_START_HEREDOC) {
                $heredoc = '';
            } elseif ($id === T_ENCAPSED_AND_WHITESPACE && $heredoc !== null) {
                $heredoc .= $text;
            } elseif ($id === T_END_HEREDOC && $heredoc !== null) {
                $statements[] = $heredoc;
                $heredoc = null;
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
                $statements[] = substr($text, 1, -1);
            }
        }

        return array_values(array_filter(
            $statements,
            static fn(string $s): bool => preg_match('/\A\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $s) === 1,
        ));
    }
}
