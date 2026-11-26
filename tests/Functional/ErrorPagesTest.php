<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

/** Error pages as in production: generic, in French, without technical details. */
final class ErrorPagesTest extends FunctionalTestCase
{
    #[\Override]
    protected static function clientOptions(): array
    {
        return ['debug' => false];
    }

    public function testUnknownPage(): void
    {
        $this->logIn();
        $this->client->request('GET', '/inexistant');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains('h1', 'Erreur 404');
        self::assertSelectorTextContains('main p', 'Page introuvable.');
        self::assertStringNotContainsString('Symfony', $this->body());
    }

    public function testUnknownAccount(): void
    {
        $this->logIn();
        $this->client->request('GET', '/comptes/424242');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertStringNotContainsString('Compte introuvable', $this->body(), 'le message interne n\'est pas affiché');
    }

    public function testMethodNotAllowed(): void
    {
        $this->logIn();
        $this->client->request('GET', '/comptes/1/supprimer');

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        self::assertSelectorTextContains('main p', 'Méthode non autorisée.');
    }

    public function testInvalidCsrfTokenOnAnActionWithoutFormPage(): void
    {
        $this->logIn();
        $this->client->request('POST', '/comptes/1/supprimer', ['_csrf' => 'invalide']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSelectorTextContains('main p', 'Le formulaire a expiré ou est invalide.');
    }

    public function testErrorPageForAnonymousVisitorHasNoNavigation(): void
    {
        $this->client->request('GET', '/inexistant');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorNotExists('nav.nav');
    }
}
