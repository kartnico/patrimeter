<?php

declare(strict_types=1);

namespace Patrimeter\Security\Vault;

use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Infrastructure\Crypto\DecryptionFailed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Data key of the user, for the time of a request.
 *
 * During the session, the key only exists on the server encrypted (session), under a random key that
 * only lives in an `HttpOnly` cookie of the browser: reading `var/sessions` is not enough to decrypt.
 * It is rebuilt in memory on demand, then erased. Refuses (fail-closed) any key of a user other than
 * the session's.
 */
final class Vault implements ResetInterface
{
    public const string COOKIE = 'patrimeter_vault';
    public const string NEW_COOKIE_ATTRIBUTE = '_patrimeter_vault_cookie';
    private const string SESSION_KEY = '_patrimeter_vault';

    /** @var array<string, string|null> user => data key (null once erased) */
    private array $keys = [];

    public function __construct(private readonly RequestStack $requests) {}

    /** Makes the key available for this request only (the session is not changed). */
    public function unlock(UserId $user, #[\SensitiveParameter] string $key): void
    {
        $this->keys[$user->value] = $key;
    }

    /** Keeps the key for the session: envelope in the session, envelope key in a cookie. */
    public function startSession(Request $request, UserId $user, #[\SensitiveParameter] string $key): void
    {
        $sessionKey = random_bytes(Aead::KEY_BYTES);
        $request->getSession()->set(self::SESSION_KEY, [
            'user' => $user->value,
            'wrapped' => Aead::seal($sessionKey, $key, self::context($user)),
        ]);
        $request->attributes->set(self::NEW_COOKIE_ATTRIBUTE, sodium_bin2base64($sessionKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING));
        sodium_memzero($sessionKey);
        $this->unlock($user, $key);
    }

    public function isUnlocked(UserId $user): bool
    {
        try {
            $this->key($user);

            return true;
        } catch (VaultLockedException) {
            return false;
        }
    }

    /** @throws VaultLockedException */
    public function key(UserId $user): string
    {
        if (!isset($this->keys[$user->value])) {
            $this->keys[$user->value] = $this->keyFromSession($user) ?? throw new VaultLockedException('Coffre verrouillé.');
        }

        return $this->keys[$user->value];
    }

    #[\Override]
    public function reset(): void
    {
        foreach (array_keys($this->keys) as $user) {
            if ($this->keys[$user] !== null) {
                sodium_memzero($this->keys[$user]);
            }
        }
        $this->keys = [];
    }

    private function keyFromSession(UserId $user): ?string
    {
        $request = $this->requests->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }
        $wrapped = self::wrappedKey($request->getSession()->get(self::SESSION_KEY), $user);
        $cookie = $request->cookies->get(self::COOKIE);
        if ($wrapped === null || !is_string($cookie)) {
            return null;
        }
        try {
            $sessionKey = sodium_base642bin($cookie, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            if (strlen($sessionKey) !== Aead::KEY_BYTES) {
                return null;
            }

            return Aead::open($sessionKey, $wrapped, self::context($user));
        } catch (DecryptionFailed|\SodiumException) {
            return null;
        }
    }

    private static function wrappedKey(mixed $stored, UserId $user): ?string
    {
        if (!is_array($stored) || ($stored['user'] ?? null) !== $user->value || !isset($stored['wrapped']) || !is_string($stored['wrapped'])) {
            return null;
        }

        return $stored['wrapped'];
    }

    private static function context(UserId $user): string
    {
        return 'session:' . $user->value;
    }
}
