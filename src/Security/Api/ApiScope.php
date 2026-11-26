<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

/** Permissions of a token: read, or read and write. Write includes read. */
final class ApiScope
{
    public const string READ = 'patrimeter.read';
    public const string WRITE = 'patrimeter.write';
    public const array ALL = [self::READ, self::WRITE];
    /** Role of a user authenticated by a token that can write (access_control of security.yaml). */
    public const string WRITE_ROLE = 'ROLE_API_WRITE';

    /**
     * Requested scope, normalised; null if it contains an unknown value. Empty: read only.
     */
    public static function parse(string $scope): ?string
    {
        $requested = array_values(array_filter(explode(' ', trim($scope)), static fn(string $part): bool => $part !== ''));
        if (array_diff($requested, self::ALL) !== []) {
            return null;
        }

        return in_array(self::WRITE, $requested, true) ? self::READ . ' ' . self::WRITE : self::READ;
    }

    public static function allowsWrite(string $scope): bool
    {
        return in_array(self::WRITE, explode(' ', $scope), true);
    }
}
