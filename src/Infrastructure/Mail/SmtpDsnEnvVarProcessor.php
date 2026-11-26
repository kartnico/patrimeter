<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Mail;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Builds the address (DSN) of the email transport from simple variables, without any encoding to do by hand:
 * `%env(smtp_dsn:SMTP_HOST)%`.
 *
 * - SMTP_HOST empty: no sending ("null" transport), useful in development and in tests.
 * - SMTP_ENCRYPTION: `starttls` (default, port 587, encryption required), `tls` (encryption from the connection,
 *   port 465) or `none` (trusted local relay, port 25).
 * - SMTP_PORT, SMTP_USER, SMTP_PASSWORD: optional.
 */
final class SmtpDsnEnvVarProcessor implements EnvVarProcessorInterface
{
    private const array DEFAULT_PORTS = ['starttls' => 587, 'tls' => 465, 'none' => 25];

    #[\Override]
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $host = self::read($getEnv, $name);
        if ($host === '') {
            return 'null://null';
        }
        $encryption = self::read($getEnv, 'SMTP_ENCRYPTION');
        $encryption = $encryption === '' ? 'starttls' : strtolower($encryption);
        if (!isset(self::DEFAULT_PORTS[$encryption])) {
            throw new RuntimeException('SMTP_ENCRYPTION : valeur attendue « starttls », « tls » ou « none ».');
        }
        $port = self::read($getEnv, 'SMTP_PORT');
        if ($port !== '' && preg_match('/\A\d{1,5}\z/', $port) !== 1) {
            throw new RuntimeException('SMTP_PORT : numéro de port attendu.');
        }
        $user = self::read($getEnv, 'SMTP_USER');
        $credentials = $user === '' ? '' : rawurlencode($user) . ':' . rawurlencode(self::read($getEnv, 'SMTP_PASSWORD')) . '@';

        return sprintf(
            '%s://%s%s:%s%s',
            $encryption === 'tls' ? 'smtps' : 'smtp',
            $credentials,
            rawurlencode($host),
            $port === '' ? self::DEFAULT_PORTS[$encryption] : $port,
            match ($encryption) {
                'starttls' => '?require_tls=true',
                'none' => '?auto_tls=false',
                default => '',
            },
        );
    }

    /** @return array<string, string> */
    #[\Override]
    public static function getProvidedTypes(): array
    {
        return ['smtp_dsn' => 'string'];
    }

    private static function read(\Closure $getEnv, string $name): string
    {
        return self::text($getEnv($name));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
