<?php

declare(strict_types=1);

namespace Patrimeter\Security\Vault;

use Patrimeter\Application\Calendar;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Infrastructure\Crypto\DecryptionFailed;
use Patrimeter\Infrastructure\Persistence\KeyEnvelopeRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Data key of each user (256 bits, created at their first sign-in) and its envelopes: one per unlock
 * method. Password: Argon2id with its own salt; recovery key: HKDF-SHA256. Changing the password only
 * redoes the envelope.
 */
final class DataKeys
{
    public const string PASSWORD = 'password';
    public const string RECOVERY = 'recovery';
    public const string PASSKEY = 'passkey';
    private const string HKDF_INFO = 'patrimeter recovery envelope';
    private const string PASSKEY_HKDF_INFO = 'patrimeter passkey envelope';
    private const int SALT_BYTES = SODIUM_CRYPTO_PWHASH_SALTBYTES;

    public function __construct(
        private readonly KeyEnvelopeRepository $envelopes,
        private readonly Vault $vault,
        private readonly Calendar $calendar,
        #[Autowire('%app.crypto.kdf_ops_limit%')]
        private readonly int $opsLimit,
        #[Autowire('%app.crypto.kdf_mem_limit%')]
        private readonly int $memLimit,
    ) {}

    /**
     * Opens the vault for the session with the password (already checked), creating the key at the
     * first sign-in. False if the envelope does not open with this password.
     */
    public function openWithPassword(Request $request, UserId $user, #[\SensitiveParameter] string $password): bool
    {
        $envelope = $this->envelopes->find($user, self::PASSWORD);
        if ($envelope === null) {
            $key = random_bytes(Aead::KEY_BYTES);
            $this->savePasswordEnvelope($user, $key, $password);
        } else {
            $key = $this->unwrapPassword($user, $envelope, $password);
            if ($key === null) {
                return false;
            }
        }
        $this->vault->startSession($request, $user, $key);

        return true;
    }

    /** Wraps the given key for this password (replaces the existing envelope). */
    public function savePasswordEnvelope(UserId $user, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $password): void
    {
        $salt = random_bytes(self::SALT_BYTES);
        $this->envelopes->save($user, self::PASSWORD, [
            'salt' => base64_encode($salt),
            'ops_limit' => $this->opsLimit,
            'mem_limit' => $this->memLimit,
            'wrapped_key' => Aead::seal($this->derive($password, $salt, $this->opsLimit, $this->memLimit), $key, self::context($user, self::PASSWORD)),
        ], $this->calendar->timestamp());
    }

    /** Data key opened with the password, or null. */
    public function keyFromPassword(UserId $user, #[\SensitiveParameter] string $password): ?string
    {
        $envelope = $this->envelopes->find($user, self::PASSWORD);

        return $envelope === null ? null : $this->unwrapPassword($user, $envelope, $password);
    }

    public function saveRecoveryEnvelope(UserId $user, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $recoveryKey): void
    {
        $salt = random_bytes(self::SALT_BYTES);
        $this->envelopes->save($user, self::RECOVERY, [
            'salt' => base64_encode($salt),
            'ops_limit' => null,
            'mem_limit' => null,
            'wrapped_key' => Aead::seal(self::deriveFromRecovery($recoveryKey, $salt), $key, self::context($user, self::RECOVERY)),
        ], $this->calendar->timestamp());
    }

    /** Data key opened with the recovery key, or null. */
    public function keyFromRecovery(UserId $user, #[\SensitiveParameter] string $recoveryKey): ?string
    {
        $envelope = $this->envelopes->find($user, self::RECOVERY);
        if ($envelope === null) {
            return null;
        }
        try {
            return Aead::open(self::deriveFromRecovery($recoveryKey, self::salt($envelope['salt'])), $envelope['wrapped_key'], self::context($user, self::RECOVERY));
        } catch (DecryptionFailed) {
            return null;
        }
    }

    /** Wraps the key with the PRF output of this passkey. */
    public function savePasskeyEnvelope(UserId $user, #[\SensitiveParameter] string $key, string $credentialId, #[\SensitiveParameter] string $prfOutput): void
    {
        $salt = random_bytes(self::SALT_BYTES);
        $this->envelopes->save($user, self::PASSKEY, [
            'salt' => base64_encode($salt),
            'ops_limit' => null,
            'mem_limit' => null,
            'wrapped_key' => Aead::seal(hash_hkdf('sha256', $prfOutput, Aead::KEY_BYTES, self::PASSKEY_HKDF_INFO, $salt), $key, self::context($user, self::PASSKEY . ':' . $credentialId)),
        ], $this->calendar->timestamp(), $credentialId);
    }

    /** Opens the vault for the session with the PRF output; false otherwise (password to ask for). */
    public function openWithPasskey(Request $request, UserId $user, string $credentialId, #[\SensitiveParameter] string $prfOutput): bool
    {
        $envelope = $this->envelopes->find($user, self::PASSKEY, $credentialId);
        if ($envelope === null) {
            return false;
        }
        try {
            $key = Aead::open(
                hash_hkdf('sha256', $prfOutput, Aead::KEY_BYTES, self::PASSKEY_HKDF_INFO, self::salt($envelope['salt'])),
                $envelope['wrapped_key'],
                self::context($user, self::PASSKEY . ':' . $credentialId),
            );
        } catch (DecryptionFailed) {
            return false;
        }
        $this->vault->startSession($request, $user, $key);

        return true;
    }

    public function deletePasskeyEnvelope(UserId $user, string $credentialId): void
    {
        $this->envelopes->delete($user, self::PASSKEY, $credentialId);
    }

    public function hasRecovery(UserId $user): bool
    {
        return $this->envelopes->exists($user, self::RECOVERY);
    }

    /** @param array{salt: string, ops_limit: ?int, mem_limit: ?int, wrapped_key: string} $envelope */
    private function unwrapPassword(UserId $user, array $envelope, #[\SensitiveParameter] string $password): ?string
    {
        if ($envelope['ops_limit'] === null || $envelope['mem_limit'] === null || $password === '') {
            return null;
        }
        try {
            return Aead::open(
                $this->derive($password, self::salt($envelope['salt']), $envelope['ops_limit'], $envelope['mem_limit']),
                $envelope['wrapped_key'],
                self::context($user, self::PASSWORD),
            );
        } catch (DecryptionFailed) {
            return null;
        }
    }

    private function derive(#[\SensitiveParameter] string $password, string $salt, int $opsLimit, int $memLimit): string
    {
        return sodium_crypto_pwhash(Aead::KEY_BYTES, $password, $salt, $opsLimit, $memLimit, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    private static function deriveFromRecovery(#[\SensitiveParameter] string $recoveryKey, string $salt): string
    {
        return hash_hkdf('sha256', $recoveryKey, Aead::KEY_BYTES, self::HKDF_INFO, $salt);
    }

    private static function salt(string $encoded): string
    {
        $salt = base64_decode($encoded, true);
        if ($salt === false || strlen($salt) !== self::SALT_BYTES) {
            throw new \UnexpectedValueException('Sel d\'enveloppe invalide.');
        }

        return $salt;
    }

    private static function context(UserId $user, string $type): string
    {
        return "key_envelopes:$type:{$user->value}:1";
    }
}
