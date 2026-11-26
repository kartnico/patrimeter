<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Calendar;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Infrastructure\Crypto\DecryptionFailed;
use Patrimeter\Infrastructure\Persistence\ApiGrantRepository;
use Patrimeter\Infrastructure\Persistence\OAuthCodeRepository;
use Patrimeter\Security\User;
use Patrimeter\Security\UserProvider;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Uid\Uuid;

/**
 * Authorization codes and tokens of the API and MCP.
 *
 * A token is "patrimeter_<selector>_<secret>" (24 hexadecimal characters, then 43 base64url ones): its fixed prefix
 * and length let secret detectors spot a token published by mistake (rule of .gitleaks.toml, PATTERN below). The
 * tokens of the former format "pm1.<selector>.<secret>" stay valid until they expire. The database only keeps the hash
 * of the secret and the data key wrapped under a key derived from it: reading the database gives neither the token
 * nor the data.
 * Like a session, a token stops being valid as soon as the password or the session stamp changes.
 */
final class ApiTokens
{
    public const int ACCESS_LIFETIME = 3600;
    public const int REFRESH_LIFETIME = 30 * 86400;
    public const int CODE_LIFETIME = 60;
    public const array PERSONAL_DAYS = [30, 90, 365];

    private const string ACCESS = 'access';
    private const string REFRESH = 'refresh';
    private const int SELECTOR_BYTES = 12;
    private const int SECRET_BYTES = 32;
    private const string HKDF_INFO = 'patrimeter api token';
    private const int TOUCH_INTERVAL = 300;
    /** Prefix of every token: recognizable by a secret detector, and by the user. */
    public const string PREFIX = 'patrimeter_';
    /** Regular expression of a token, current or former format, for the secret detectors (.gitleaks.toml). */
    public const string PATTERN = '(?:patrimeter_[0-9a-f]{24}_|pm1\.[0-9a-f]{24}\.)[A-Za-z0-9_-]{43}';
    private const string FORMAT = '/\A(?:patrimeter_([0-9a-f]{24})_|pm1\.([0-9a-f]{24})\.)([A-Za-z0-9_-]{43})\z/';
    private const string VERIFIER_FORMAT = '/\A[A-Za-z0-9._~-]{43,128}\z/';

    public function __construct(
        private readonly ApiGrantRepository $grants,
        private readonly OAuthCodeRepository $codes,
        private readonly UserProvider $users,
        private readonly Calendar $calendar,
        private readonly Connection $connection,
    ) {}

    public function issueCode(User $user, string $clientId, string $redirectUri, string $challenge, string $scope, string $resource, #[\SensitiveParameter] string $dataKey): string
    {
        [$token, $selector, $hash, $wrapped] = $this->seal($dataKey, 'code');
        $this->codes->create($selector, $user->id, $clientId, $redirectUri, $challenge, $scope, $resource, $hash, $wrapped, $this->calendar->timestamp(self::CODE_LIFETIME));

        return $token;
    }

    /**
     * Exchanges a code for tokens, once only.
     *
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}|null
     */
    public function exchangeCode(#[\SensitiveParameter] string $code, string $clientId, string $redirectUri, #[\SensitiveParameter] string $verifier, string $resource, string $clientName): ?array
    {
        $parts = self::parse($code);
        $row = $parts === null ? null : $this->codes->find($parts[0]);
        if ($parts === null || $row === null || !hash_equals($row['verifier_hash'], self::hash($parts[1]))
            || $row['used_at'] !== null || $row['expires_at'] < $this->calendar->timestamp()
            || $row['client_id'] !== $clientId || $row['redirect_uri'] !== $redirectUri
            || ($resource !== '' && $resource !== $row['resource'])
            || preg_match(self::VERIFIER_FORMAT, $verifier) !== 1 || !hash_equals($row['code_challenge'], self::challenge($verifier))
            || !$this->codes->markUsed($parts[0], $this->calendar->timestamp())) {
            return null;
        }
        $user = $this->user($row['user_id']);
        $key = self::open($parts, $row['wrapped_key'], 'code');
        if ($user === null || $key === null) {
            return null;
        }
        $grant = Uuid::v7()->toRfc4122();

        return $this->connection->transactional(function () use ($grant, $user, $row, $clientId, $clientName, $key): array {
            $this->grants->createGrant($grant, $user->id, $clientId, $clientName, $row['scope'], $row['resource'], $user->credentialsFingerprint(), null, $this->calendar->timestamp());

            return $this->tokenPair($grant, $row['scope'], $key);
        });
    }

    /**
     * Rotates a refresh token. Replayed, it reveals a theft: the whole authorization falls.
     *
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}|null
     */
    public function refresh(#[\SensitiveParameter] string $refreshToken, string $clientId): ?array
    {
        $parts = self::parse($refreshToken);
        $token = $parts === null ? null : $this->grants->findToken($parts[0]);
        if ($parts === null || $token === null || $token['kind'] !== self::REFRESH || !hash_equals($token['verifier_hash'], self::hash($parts[1]))) {
            return null;
        }
        $grant = $this->grants->findGrant($token['grant_id']);
        if ($grant === null || $grant['client_id'] !== $clientId) {
            return null;
        }
        if ($token['used_at'] !== null) {
            $this->grants->revokeById($grant['id']);

            return null;
        }
        $user = $this->user($grant['user_id']);
        $key = self::open($parts, $token['wrapped_key'], self::REFRESH);
        if ($token['expires_at'] < $this->calendar->timestamp() || $user === null || $key === null
            || !hash_equals($grant['fingerprint'], $user->credentialsFingerprint())
            || !$this->grants->markTokenUsed($parts[0], $this->calendar->timestamp())) {
            return null;
        }

        return $this->tokenPair($grant['id'], $grant['scope'], $key);
    }

    /** Personal token, created from the My account page: valid for the API and for MCP. */
    public function createPersonal(User $user, string $name, string $scope, int $days, #[\SensitiveParameter] string $dataKey): string
    {
        $grant = Uuid::v7()->toRfc4122();
        $expires = $this->calendar->timestamp($days * 86400);
        $this->grants->createGrant($grant, $user->id, null, $name, $scope, ApiResource::ANY, $user->credentialsFingerprint(), $expires, $this->calendar->timestamp());

        return $this->saveToken($grant, self::ACCESS, $dataKey, $expires);
    }

    /** Valid access token for this resource, or null. */
    public function authenticate(#[\SensitiveParameter] string $token, string $resource): ?ApiAccess
    {
        $parts = self::parse($token);
        $row = $parts === null ? null : $this->grants->findToken($parts[0]);
        if ($parts === null || $row === null || $row['kind'] !== self::ACCESS || !hash_equals($row['verifier_hash'], self::hash($parts[1])) || $row['expires_at'] < $this->calendar->timestamp()) {
            return null;
        }
        $grant = $this->grants->findGrant($row['grant_id']);
        if ($grant === null || ($grant['resource'] !== ApiResource::ANY && $grant['resource'] !== $resource)) {
            return null;
        }
        $user = $this->user($grant['user_id']);
        $key = self::open($parts, $row['wrapped_key'], self::ACCESS);
        if ($user === null || $key === null || !hash_equals($grant['fingerprint'], $user->credentialsFingerprint())) {
            return null;
        }
        if ($grant['last_used_at'] === null || $grant['last_used_at'] < $this->calendar->timestamp(-self::TOUCH_INTERVAL)) {
            $this->grants->touch($grant['id'], $this->calendar->timestamp());
        }

        return new ApiAccess($user, $grant['scope'], $key);
    }

    /** RFC 7009: revocation by the client, without effect if the token is unknown or foreign to this client. */
    public function revokeByToken(#[\SensitiveParameter] string $token, string $clientId): void
    {
        $parts = self::parse($token);
        $row = $parts === null ? null : $this->grants->findToken($parts[0]);
        if ($parts === null || $row === null || !hash_equals($row['verifier_hash'], self::hash($parts[1]))) {
            return;
        }
        $grant = $this->grants->findGrant($row['grant_id']);
        if ($grant !== null && $grant['client_id'] === $clientId) {
            $this->grants->revokeById($grant['id']);
        }
    }

    /** @return list<array{id: string, name: string, scope: string, personal: bool, created_at: string, last_used_at: ?string, expires_at: ?string}> */
    public function grantsOf(UserId $user): array
    {
        $now = $this->calendar->timestamp();
        $current = array_filter($this->grants->allOf($user), static fn(array $grant): bool => $grant['expires_at'] === null || $grant['expires_at'] >= $now);

        return array_values(array_map(static fn(array $grant): array => [
            'id' => $grant['id'],
            'name' => $grant['name'],
            'scope' => $grant['scope'],
            'personal' => $grant['client_id'] === null,
            'created_at' => $grant['created_at'],
            'last_used_at' => $grant['last_used_at'],
            'expires_at' => $grant['expires_at'],
        ], $current));
    }

    public function revoke(UserId $user, string $grantId): bool
    {
        return $this->grants->revoke($user, $grantId);
    }

    /** PKCE S256 challenge of a verifier (RFC 7636). */
    public static function challenge(#[\SensitiveParameter] string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string} */
    private function tokenPair(string $grant, string $scope, #[\SensitiveParameter] string $key): array
    {
        return [
            'access_token' => $this->saveToken($grant, self::ACCESS, $key, $this->calendar->timestamp(self::ACCESS_LIFETIME)),
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_LIFETIME,
            'refresh_token' => $this->saveToken($grant, self::REFRESH, $key, $this->calendar->timestamp(self::REFRESH_LIFETIME)),
            'scope' => $scope,
        ];
    }

    private function saveToken(string $grant, string $kind, #[\SensitiveParameter] string $key, string $expiresAt): string
    {
        [$token, $selector, $hash, $wrapped] = $this->seal($key, $kind);
        $this->grants->saveToken($selector, $grant, $kind, $hash, $wrapped, $expiresAt);

        return $token;
    }

    /** @return array{string, string, string, string} token, selector, hash, wrapped key */
    private function seal(#[\SensitiveParameter] string $key, string $kind): array
    {
        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $secret = random_bytes(self::SECRET_BYTES);
        $encoded = sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $wrapped = Aead::seal(self::wrappingKey($secret, $selector), $key, self::context($selector, $kind));

        return [self::PREFIX . $selector . '_' . $encoded, $selector, hash('sha256', $secret), $wrapped];
    }

    /** @param array{string, string} $parts */
    private static function open(array $parts, string $wrapped, string $kind): ?string
    {
        try {
            return Aead::open(self::wrappingKey(self::secret($parts[1]), $parts[0]), $wrapped, self::context($parts[0], $kind));
        } catch (DecryptionFailed) {
            return null;
        }
    }

    /** @return array{string, string}|null selector and encoded secret */
    private static function parse(#[\SensitiveParameter] string $token): ?array
    {
        if (preg_match(self::FORMAT, $token, $match) !== 1) {
            return null;
        }

        return [$match[1] !== '' ? $match[1] : $match[2], $match[3]];
    }

    private static function hash(#[\SensitiveParameter] string $encodedSecret): string
    {
        return hash('sha256', self::secret($encodedSecret));
    }

    private static function secret(#[\SensitiveParameter] string $encoded): string
    {
        try {
            return sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            return '';
        }
    }

    private static function wrappingKey(#[\SensitiveParameter] string $secret, string $selector): string
    {
        return hash_hkdf('sha256', $secret, Aead::KEY_BYTES, self::HKDF_INFO, $selector);
    }

    private static function context(string $selector, string $kind): string
    {
        return "api-token:$kind:$selector";
    }

    private function user(UserId $id): ?User
    {
        try {
            $user = $this->users->loadUserByIdentifier($id->value);
        } catch (UserNotFoundException) {
            return null;
        }

        return $user->isTotpAuthenticationEnabled() ? $user : null;
    }

    /** Forgets the expired codes, tokens and authorizations (scheduled task). */
    public function purgeExpired(): void
    {
        $now = $this->calendar->timestamp();
        $this->codes->purge($now);
        $this->grants->purge($now);
    }
}
