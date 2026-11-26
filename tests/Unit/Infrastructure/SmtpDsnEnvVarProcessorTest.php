<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Infrastructure;

use Patrimeter\Infrastructure\Mail\SmtpDsnEnvVarProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

final class SmtpDsnEnvVarProcessorTest extends TestCase
{
    public function testEmptyHostDisablesSending(): void
    {
        self::assertSame('null://null', self::dsn([]));
    }

    public function testStartTlsIsTheDefaultAndRequired(): void
    {
        self::assertSame('smtp://smtp.example.fr:587?require_tls=true', self::dsn(['SMTP_HOST' => 'smtp.example.fr']));
    }

    public function testImplicitTlsAndPlainRelay(): void
    {
        self::assertSame('smtps://smtp.example.fr:465', self::dsn(['SMTP_HOST' => 'smtp.example.fr', 'SMTP_ENCRYPTION' => 'TLS']));
        self::assertSame('smtp://localhost:1025?auto_tls=false', self::dsn(['SMTP_HOST' => 'localhost', 'SMTP_PORT' => '1025', 'SMTP_ENCRYPTION' => 'none']));
    }

    public function testCredentialsAreUrlEncoded(): void
    {
        self::assertSame(
            'smtp://patrimeter%40example.fr:p%40ss%3Aw%2Frd%20%21@smtp.example.fr:2525?require_tls=true',
            self::dsn(['SMTP_HOST' => 'smtp.example.fr', 'SMTP_PORT' => '2525', 'SMTP_USER' => 'patrimeter@example.fr', 'SMTP_PASSWORD' => 'p@ss:w/rd !']),
        );
    }

    public function testInvalidEncryptionIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        self::dsn(['SMTP_HOST' => 'smtp.example.fr', 'SMTP_ENCRYPTION' => 'ssl3']);
    }

    public function testInvalidPortIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        self::dsn(['SMTP_HOST' => 'smtp.example.fr', 'SMTP_PORT' => '25; rm']);
    }

    public function testProvidesTheSmtpDsnPrefix(): void
    {
        self::assertSame(['smtp_dsn' => 'string'], SmtpDsnEnvVarProcessor::getProvidedTypes());
    }

    /** @param array<string, string> $env */
    private static function dsn(array $env): string
    {
        return (new SmtpDsnEnvVarProcessor())->getEnv('smtp_dsn', 'SMTP_HOST', static fn(string $name): ?string => $env[$name] ?? null);
    }
}
