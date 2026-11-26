<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

use Patrimeter\Security\User;

/** Result of a valid access token: its user, its scope and the data key it opens. */
final class ApiAccess
{
    public function __construct(
        public readonly User $user,
        public readonly string $scope,
        #[\SensitiveParameter]
        public readonly string $dataKey,
    ) {}
}
