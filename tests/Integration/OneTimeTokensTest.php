<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Doctrine\DBAL\Connection;
use Patrimeter\Security\OneTimeTokens;
use Patrimeter\Tests\Support\IntegrationTestCase;

final class OneTimeTokensTest extends IntegrationTestCase
{
    private OneTimeTokens $tokens;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->tokens = self::service(OneTimeTokens::class);
    }

    public function testTokenIsValidOnceForItsPurposeOnly(): void
    {
        $user = self::owner();
        $token = $this->tokens->issue($user, OneTimeTokens::EMAIL_VERIFICATION, 3600);

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}\z/', $token);
        self::assertNull($this->tokens->peek($token, 'autre_usage'));
        self::assertTrue($user->equals($this->tokens->peek($token, OneTimeTokens::EMAIL_VERIFICATION) ?? self::fail()));
        self::assertTrue($user->equals($this->tokens->consume($token, OneTimeTokens::EMAIL_VERIFICATION) ?? self::fail()));
        self::assertNull($this->tokens->consume($token, OneTimeTokens::EMAIL_VERIFICATION), 'usage unique');
        self::assertNull($this->tokens->peek($token, OneTimeTokens::EMAIL_VERIFICATION));
    }

    public function testOnlyTheVerifierHashIsStored(): void
    {
        $token = $this->tokens->issue(self::owner(), OneTimeTokens::EMAIL_VERIFICATION, 3600);
        [$selector, $verifier] = explode('.', $token);

        $row = self::service(Connection::class)->fetchAssociative('SELECT selector, verifier_hash FROM one_time_tokens');
        self::assertSame(['selector' => $selector, 'verifier_hash' => hash('sha256', $verifier)], $row);
    }

    public function testWrongVerifierMalformedTokenAndExpiryAreRejected(): void
    {
        $token = $this->tokens->issue(self::owner(), OneTimeTokens::EMAIL_VERIFICATION, 3600);
        $forged = substr($token, 0, 23) . str_repeat('A', 43);

        self::assertNull($this->tokens->consume($forged, OneTimeTokens::EMAIL_VERIFICATION));
        self::assertNull($this->tokens->peek('pas-un-jeton', OneTimeTokens::EMAIL_VERIFICATION));
        self::clock()->sleep(3600);
        self::assertNull($this->tokens->peek($token, OneTimeTokens::EMAIL_VERIFICATION), 'expiré');
    }

    public function testRevokeAllAndPurge(): void
    {
        $user = self::owner();
        $first = $this->tokens->issue($user, OneTimeTokens::EMAIL_VERIFICATION, 3600);
        $this->tokens->revokeAll($user, OneTimeTokens::EMAIL_VERIFICATION);
        self::assertNull($this->tokens->peek($first, OneTimeTokens::EMAIL_VERIFICATION));

        self::clock()->sleep(2 * 86400);
        $this->tokens->issue($user, OneTimeTokens::EMAIL_VERIFICATION, 3600);
        self::assertSame(2, self::queryInt('SELECT COUNT(*) FROM one_time_tokens'), 'rien n\'est purgé pendant une requête');
        $this->tokens->purgeExpired();
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM one_time_tokens'), 'anciens jetons purgés par la tâche planifiée');
    }
}
