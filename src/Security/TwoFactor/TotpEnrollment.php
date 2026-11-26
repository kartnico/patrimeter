<?php

declare(strict_types=1);

namespace Patrimeter\Security\TwoFactor;

use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Patrimeter\Application\Calendar;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\AuthSecrets;
use Patrimeter\Security\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Setting up (or setting up again) the authenticator app.
 *
 * The new secret stays pending in the session, encrypted with the server key, until a first valid code
 * proves it has been saved in the app: the previous secret, if any, stays active until then.
 */
final class TotpEnrollment
{
    public const string ISSUER = 'Patrimeter';
    private const string SESSION_KEY = '_patrimeter_totp_pending';
    private const int SECRET_BYTES = 20;
    private const int PERIOD = 30;
    private const int DIGITS = 6;

    public function __construct(
        private readonly AuthSecrets $secrets,
        private readonly UserRepository $users,
        private readonly ClockInterface $clock,
        private readonly Calendar $calendar,
        /** @var int<0, max> seconds of tolerance (scheb_two_factor.totp.leeway, positive) */
        #[Autowire('%scheb_two_factor.totp.leeway%')]
        private readonly int $leeway,
    ) {}

    /** Pending secret for this user (created at the first call). */
    public function pendingSecret(Request $request, User $user): string
    {
        $session = $request->getSession();
        $stored = self::storedSecret($session->get(self::SESSION_KEY), $user);
        if ($stored !== null) {
            try {
                return $this->secrets->decryptTotpSecret($user->id, $stored);
            } catch (\UnexpectedValueException) {
                // Server key changed in the meantime: a new secret is offered.
            }
        }
        $secret = Base32::encodeUpperUnpadded(random_bytes(self::SECRET_BYTES));
        $session->set(self::SESSION_KEY, ['user' => $user->id->value, 'secret' => $this->secrets->encryptTotpSecret($user->id, $secret)]);

        return $secret;
    }

    /** otpauth:// address to scan (QR code). */
    public function provisioningUri(User $user, #[\SensitiveParameter] string $secret): string
    {
        $totp = $this->totp($secret);
        $totp->setLabel($user->email !== null && $user->email !== '' ? $user->email : $user->id->value);
        $totp->setIssuer(self::ISSUER);

        return $totp->getProvisioningUri();
    }

    /** Activates the pending secret if the code is valid; true on success. */
    public function confirm(Request $request, User $user, #[\SensitiveParameter] string $code): bool
    {
        $secret = $this->pendingSecret($request, $user);
        $code = (string) preg_replace('/\s+/', '', $code);
        if ($code === '' || preg_match('/\A\d{' . self::DIGITS . '}\z/', $code) !== 1 || !$this->totp($secret)->verify($code, null, $this->leeway)) {
            return false;
        }
        $this->users->enableTotp($user->id, $this->secrets->encryptTotpSecret($user->id, $secret), $this->calendar->timestamp());
        $request->getSession()->remove(self::SESSION_KEY);

        return true;
    }

    /** Encrypted secret kept in the session for this user, if there is one. */
    private static function storedSecret(mixed $stored, User $user): ?string
    {
        if (!is_array($stored) || ($stored['user'] ?? null) !== $user->id->value || !isset($stored['secret']) || !is_string($stored['secret'])) {
            return null;
        }

        return $stored['secret'];
    }

    /** Groups of 4 characters, for an error-free manual entry. */
    public static function formatSecret(#[\SensitiveParameter] string $secret): string
    {
        return implode(' ', str_split($secret, 4));
    }

    private function totp(#[\SensitiveParameter] string $secret): TOTP
    {
        if ($secret === '') {
            throw new \LogicException('Secret TOTP vide.');
        }

        return TOTP::create($secret, self::PERIOD, 'sha1', self::DIGITS, clock: $this->clock);
    }
}
