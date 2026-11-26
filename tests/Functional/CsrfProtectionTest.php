<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

/** Any POST without a valid token is refused before reaching the controller. */
final class CsrfProtectionTest extends FunctionalTestCase
{
    public function testFormPageIsShownAgainAndNothingIsSaved(): void
    {
        $this->logIn();
        $this->client->request('POST', '/comptes/nouveau', ['_csrf' => 'invalide', 'name' => 'Livret', 'type_id' => '4', 'opened_on' => '2026-01-01']);

        self::assertResponseRedirects('/comptes/nouveau', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Le formulaire a expiré : rien n\'a été enregistré.', $this->flashes());
        self::assertSame([], self::service(AccountRepository::class)->all(self::owner()));
    }

    public function testMissingTokenOnMonthlyEntry(): void
    {
        $this->logIn();
        $this->client->request('POST', '/saisie', ['mois' => '2026-11']);

        self::assertResponseRedirects('/saisie', Response::HTTP_SEE_OTHER);
        self::assertSame([], self::service(StatementRepository::class)->all(self::owner()));
    }

    public function testAnonymousPostIsSentToLoginBeforeAnyTokenCheck(): void
    {
        $this->client->request('POST', '/saisie', ['_csrf' => 'invalide']);

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
    }
}
