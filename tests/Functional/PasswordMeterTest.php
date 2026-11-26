<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\PasswordPolicy;
use Patrimeter\Tests\Support\FunctionalTestCase;

/**
 * Strength of a new password in real time (app.js) and requirements of PasswordRules, at registration and when
 * changing the password. The estimate itself is tested in tests/js/app.test.mjs, against the server's levels.
 */
final class PasswordMeterTest extends FunctionalTestCase
{
    public function testRegistrationShowsTheRequirementsAndPreparesTheStrengthMeter(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        $meter = $crawler->filter('#password-meter[data-password-meter]');
        self::assertCount(1, $meter);
        self::assertSame('password', $meter->attr('data-for'));
        self::assertSame('password_confirmation', $meter->attr('data-confirm'));
        self::assertSame('email', $meter->attr('data-email-input'), 'the address typed in the form');
        self::assertSame((string) PasswordPolicy::MIN_LENGTH, $meter->attr('data-min-length'));
        self::assertSame(['Très faible', 'Faible', 'Moyenne', 'Forte', 'Très forte'], array_map(
            static fn(int $level): ?string => $meter->attr("data-level-$level"),
            range(0, 4),
        ));
        // Strength shown by JavaScript only; the requirements are listed even without it.
        self::assertNotNull($meter->filter('[data-password-strength]')->attr('hidden'));
        self::assertSame(['length', 'email', 'strength', 'match'], $meter->filter('[data-rule]')->each(static fn($rule): ?string => $rule->attr('data-rule')));
        self::assertStringContainsString('faire au moins 12 caractères', $meter->text());
        self::assertStringContainsString('fuites de données connues', $meter->text());
        self::assertStringContainsString('password-meter', (string) $crawler->filter('#password')->attr('aria-describedby'));
    }

    public function testPasswordChangeChecksTheAddressOfTheAccount(): void
    {
        $this->logIn();
        $crawler = $this->client->request('GET', '/securite');

        $meter = $crawler->filter('#new_password-meter');
        self::assertSame('new_password', $meter->attr('data-for'));
        self::assertSame(self::EMAIL, $meter->attr('data-email'));
        self::assertSame('new_password-meter new-password-hint', $crawler->filter('#new_password')->attr('aria-describedby'));
    }
}
