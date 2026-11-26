<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Patrimeter\Command\AuthSecretsRotateCommand;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\AuthSecrets;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Rotation of AUTH_SECRET_KEY: TOTP secrets encrypted again with the new key, old key required. */
final class AuthSecretsRotateCommandTest extends KernelTestCase
{
    /** Test key of .env.test (32 zero bytes). */
    private const string TEST_KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    public function testTotpSecretsAreReencryptedWithTheNewKey(): void
    {
        self::bootKernel();
        $id = TestUsers::create(static::getContainer(), 'rotation@example.test');
        $newKey = base64_encode(random_bytes(32));
        $users = static::getContainer()->get(UserRepository::class);

        $tester = new CommandTester(new AuthSecretsRotateCommand(new AuthSecrets($newKey, 'test', self::TEST_KEY), $users));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('rechiffré', $tester->getDisplay());

        $stored = $users->totpSecrets()[$id->value];
        self::assertSame(TestUsers::TOTP_SECRET, (new AuthSecrets($newKey, 'test'))->decryptTotpSecret($id, $stored));
        $this->expectException(\UnexpectedValueException::class);
        (new AuthSecrets(self::TEST_KEY, 'test'))->decryptTotpSecret($id, $stored);
    }

    public function testRotationNeedsThePreviousKey(): void
    {
        self::bootKernel();
        $users = static::getContainer()->get(UserRepository::class);

        $tester = new CommandTester(new AuthSecretsRotateCommand(new AuthSecrets(self::TEST_KEY, 'test'), $users));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('AUTH_SECRET_KEY_PREVIOUS', $tester->getDisplay());
    }
}
