<?php

declare(strict_types=1);

namespace Patrimeter\Security\TwoFactor;

use Patrimeter\Application\Calendar;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\TrustedBrowserRepository;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\User;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Trusted browsers: once the second factor is passed with "trust this browser" ticked, the sign-in on this browser no
 * longer asks for the code for DAYS days; the password (or the passkey) is still asked for.
 *
 * The browser keeps a random cookie "<selector>.<secret>" (HttpOnly, SameSite=Strict, and Secure with the __Host-
 * prefix over HTTPS); the database only keeps the fingerprint of the secret, never the cookie. The trust is tied to the
 * credentials of that time: a new password, or the other sessions signed out (session stamp), make it fall. Each
 * browser can be removed from My account, one by one or all together.
 */
final class TrustedBrowsers
{
    public const int DAYS = 30;
    /** Field of the second factor form: "trust this browser" ticked. */
    public const string FIELD = '_trust_browser';
    /** Request attribute: the second factor was skipped thanks to a trusted browser (sign-in log). */
    public const string USED = '_patrimeter_trusted_browser';

    private const string COOKIE = 'patrimeter_trusted';
    private const string SECURE_COOKIE = '__Host-patrimeter_trusted';
    private const int SELECTOR_BYTES = 16;
    private const int SECRET_BYTES = 32;
    private const string FORMAT = '/\A([0-9a-f]{32})\.([A-Za-z0-9_-]{43})\z/';

    public function __construct(
        private readonly TrustedBrowserRepository $browsers,
        private readonly Calendar $calendar,
    ) {}

    /** True if this request comes from a browser the user trusted, still valid for their current credentials. */
    public function isTrusted(Request $request, User $user): bool
    {
        $parts = self::parse($request);
        $row = $parts === null ? null : $this->browsers->find($user->id, $parts[0]);
        if ($parts === null || $row === null || $row['expires_at'] < $this->calendar->timestamp()
            || !hash_equals($row['verifier_hash'], self::hash($parts[1]))
            || !hash_equals($row['fingerprint'], $user->credentialsFingerprint())) {
            return false;
        }
        $this->browsers->touch($user->id, $parts[0], $this->calendar->timestamp());

        return true;
    }

    /** Trusts the browser of this request: the cookie to send back with the response. */
    public function trust(Request $request, User $user): Cookie
    {
        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $secret = sodium_bin2base64(random_bytes(self::SECRET_BYTES), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $userAgent = $request->headers->get('User-Agent');
        $this->browsers->create(
            $user->id,
            $selector,
            self::hash($secret),
            $user->credentialsFingerprint(),
            $request->getClientIp(),
            $userAgent === null ? null : mb_substr($userAgent, 0, AuthJournal::USER_AGENT_MAX),
            $this->calendar->timestamp(),
            $this->calendar->timestamp(self::DAYS * 86400),
        );
        $secure = $request->isSecure();

        return Cookie::create(
            $secure ? self::SECURE_COOKIE : self::COOKIE,
            "$selector.$secret",
            $this->calendar->now()->getTimestamp() + self::DAYS * 86400,
            '/',
            null,
            $secure,
            true,
            false,
            Cookie::SAMESITE_STRICT,
        );
    }

    /**
     * Browsers of the user still trusted, the current one marked.
     *
     * @return list<array{id: string, ip: ?string, user_agent: ?string, created_at: string, last_used_at: ?string, expires_at: string, current: bool}>
     */
    public function list(Request $request, UserId $user): array
    {
        $current = self::parse($request)[0] ?? null;

        return array_map(static fn(array $row): array => [
            'id' => $row['id'],
            'ip' => $row['ip'],
            'user_agent' => $row['user_agent'],
            'created_at' => $row['created_at'],
            'last_used_at' => $row['last_used_at'],
            'expires_at' => $row['expires_at'],
            'current' => $row['id'] === $current,
        ], $this->browsers->current($user, $this->calendar->timestamp()));
    }

    public function revoke(UserId $user, string $id): bool
    {
        return $this->browsers->delete($user, $id);
    }

    public function revokeAll(UserId $user): int
    {
        return $this->browsers->deleteAll($user);
    }

    /** Forgets the expired browsers (scheduled task). */
    public function purgeExpired(): void
    {
        $this->browsers->purge($this->calendar->timestamp());
    }

    /** @return array{string, string}|null selector and secret of the cookie */
    private static function parse(Request $request): ?array
    {
        $value = $request->cookies->get(self::SECURE_COOKIE) ?? $request->cookies->get(self::COOKIE);

        return is_string($value) && preg_match(self::FORMAT, $value, $match) === 1 ? [$match[1], $match[2]] : null;
    }

    private static function hash(#[\SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }
}
