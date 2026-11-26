<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Infrastructure\Persistence\GameRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

/** Without any account, the wealth and entry pages stay on the current month: the first accounts are created there. */
final class NoAccountMonthTest extends FunctionalTestCase
{
    public function testWithoutAccountTheMonthCannotBeChanged(): void
    {
        $this->logIn();
        foreach (['/' => '/?mois=2026-05', '/saisie' => '/saisie?mois=2026-12'] as $page => $otherMonth) {
            $this->client->request('GET', $otherMonth);
            self::assertResponseRedirects($page, Response::HTTP_SEE_OTHER, $otherMonth);
            $this->client->request('GET', $page);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.month-nav', $page);
        }
        // Nothing to copy from another month: only the creation of the first accounts and properties.
        $this->client->request('GET', '/saisie');
        self::assertSelectorNotExists('form.copy-form');
        self::assertSelectorExists('.empty-state a.btn-primary[href="/comptes/nouveau"]');
        self::assertSelectorExists('.empty-state a[href="/biens/nouveau"]');
    }

    public function testTheWealthPageGuidesUntilAnAccountAndAPropertyExist(): void
    {
        $this->logIn();
        $this->client->request('GET', '/');
        self::assertSelectorExists('.welcome a[href="/comptes/nouveau"]');
        self::assertSelectorExists('.welcome a[href="/biens/nouveau"]');

        // Challenges on (default): the first-steps card guides, a step after the other.
        self::createAccount('Livret A', 'livret_a', '2020-01-01');
        $this->client->request('GET', '/');
        self::assertSelectorExists('.first-steps a[href="/biens/nouveau"]', 'an account, no property yet');
        self::assertSelectorNotExists('.onboarding');

        self::createAccount('Maison', 'residence_principale', '2020-01-01');
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.first-steps a[href="/biens/nouveau"]', 'the property step is done');
        self::assertSelectorExists('.first-steps li.is-done', 'and ticked');
    }

    public function testWithoutChallengesTheFormerGuidanceStays(): void
    {
        self::service(GameRepository::class)->saveEnabled(self::owner(), false);
        self::createAccount('Livret A', 'livret_a', '2020-01-01');
        $this->logIn();
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.first-steps');
        self::assertSelectorExists('.onboarding a[href="/biens/nouveau"]', 'an account, no property yet');
    }

    public function testAPropertyAloneLeadsToTheAccounts(): void
    {
        self::service(GameRepository::class)->saveEnabled(self::owner(), false);
        self::createAccount('Maison', 'residence_principale', '2020-01-01');
        $this->logIn();
        $this->client->request('GET', '/');
        self::assertSelectorExists('.onboarding a[href="/comptes/nouveau"]');
    }

    public function testAPropertyAloneDoesNotTickTheAccountsStep(): void
    {
        self::createAccount('Maison', 'residence_principale', '2020-01-01');
        $this->logIn();
        $this->client->request('GET', '/');
        self::assertSelectorExists('.first-steps li:not(.is-done) a[href="/bienvenue/express"]', 'accounts step still to do');
    }

    public function testWithAnAccountTheMonthCanBeChanged(): void
    {
        self::createAccount('Livret A', 'livret_a', '2020-01-01');
        $this->logIn();
        foreach (['/?mois=2026-05', '/saisie?mois=2026-05'] as $otherMonth) {
            $this->client->request('GET', $otherMonth);
            self::assertResponseIsSuccessful($otherMonth);
            self::assertSelectorExists('.month-nav');
        }
    }
}
