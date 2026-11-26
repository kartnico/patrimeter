<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

use Patrimeter\Security\User;
use Patrimeter\Security\Vault\Vault;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Bearer token of the API and of MCP, for Symfony's native "access_token" authenticator. A token always comes from
 * a sign-in with the usual means (password and TOTP, or passkey); it opens the data key for this request only.
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokens $tokens,
        private readonly ApiResource $resources,
        private readonly Vault $vault,
        private readonly RequestStack $requests,
    ) {}

    #[\Override]
    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $path = $this->requests->getMainRequest()?->getPathInfo() ?? '';
        $access = $this->tokens->authenticate($accessToken, $this->resources->forPath($path));
        if ($access === null) {
            throw new BadCredentialsException('Jeton invalide.');
        }
        $this->vault->unlock($access->user->id, $access->dataKey);
        $user = ApiScope::allowsWrite($access->scope) ? $access->user->withApiWrite() : $access->user;

        return new UserBadge($user->id->value, static fn(): User => $user);
    }
}
