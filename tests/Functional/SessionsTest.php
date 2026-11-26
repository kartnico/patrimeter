<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\UserSessionRepository;
use Patrimeter\Security\UserSessions;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;

/**
 * Open sessions on the sign-in and security tab: listed like the sign-in log, the current one marked, the others signed
 * out one by one or all together once the identity is confirmed; never the current one, never another user's.
 */
final class SessionsTest extends FunctionalTestCase
{
    public function testTheSessionsAreListedAndAnotherOneCanBeSignedOut(): void
    {
        $this->logIn();
        $this->client->request('GET', '/securite/connexion');
        $otherDevice = $this->saveSession();
        $this->client->getCookieJar()->clear();
        self::clock()->sleep(30);
        $this->logIn();

        $crawler = $this->client->request('GET', '/securite/connexion');
        self::assertCount(2, $crawler->filter('#sessions tbody tr'));
        self::assertStringContainsString('cette session', $crawler->filter('#sessions tr.is-current')->text());
        self::assertSelectorNotExists('#sessions form', 'identity not confirmed yet');
        self::assertSelectorTextContains('#sessions .identity-notice', 'Confirmez votre identité ci-dessus pour déconnecter une session.');

        $this->reauthenticate();
        $crawler = $this->client->request('GET', '/securite/connexion');
        $forms = $crawler->filter('#sessions tbody form');
        self::assertCount(1, $forms, 'never a sign-out button on the current session');
        self::assertCount(1, $crawler->filter('#sessions form[action="/securite/sessions"]'), 'and the button for all the others');

        $this->submit((string) $forms->attr('action'), [], '/securite/connexion');
        self::assertResponseRedirects('/securite/connexion#sessions', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('La session a été déconnectée.', $this->flashes());
        self::assertCount(1, $this->client->getCrawler()->filter('#sessions tbody tr'));
        self::assertStringContainsString('Une session déconnectée', $this->client->getCrawler()->filter('#journal')->text());

        $this->restoreSession($otherDevice);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Cette session a été déconnectée depuis un autre appareil.', $this->flashes());
    }

    public function testNeitherTheCurrentSessionNorAnotherUsersCanBeSignedOut(): void
    {
        $sessions = self::service(UserSessionRepository::class);
        $neighbour = TestUsers::findOrCreate(self::getContainer(), TestUsers::OTHER_EMAIL);
        $sessions->create($neighbour, str_repeat('a', 32), null, null, '2026-11-15T11:59:00Z');
        $this->logIn();
        $this->client->request('GET', '/securite/connexion');
        $this->reauthenticate();

        $this->submit('/securite/sessions/' . str_repeat('a', 32) . '/deconnecter', [], '/securite/connexion');
        $this->client->followRedirect();
        self::assertStringContainsString('Cette session n\'existe plus', $this->flashes());
        self::assertTrue($sessions->isActive($neighbour, str_repeat('a', 32)), 'the neighbour\'s session is untouched');

        $current = $this->sessionIds(self::owner());
        self::assertCount(1, $current);
        $this->submit('/securite/sessions/' . $current[0] . '/deconnecter', [], '/securite/connexion');
        $this->client->followRedirect();
        self::assertStringContainsString('Cette session n\'existe plus', $this->flashes());
        $this->client->request('GET', '/securite/connexion');
        self::assertResponseIsSuccessful('still signed in');
    }

    public function testSigningOutAllTheOtherSessionsKeepsTheCurrentOne(): void
    {
        $this->logIn();
        $this->client->request('GET', '/securite/connexion');
        $this->client->getCookieJar()->clear();
        self::clock()->sleep(30);
        $this->logIn();
        $this->client->request('GET', '/securite/connexion');
        $this->reauthenticate();

        $this->submit('/securite/sessions', [], '/securite/connexion');
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('#sessions tbody tr'));
        self::assertCount(1, $crawler->filter('#sessions tr.is-current'));
    }

    public function testSignOutForgetsTheSessionAndExpiredOnesAreHiddenThenPurged(): void
    {
        $this->logIn();
        $this->client->request('GET', '/securite/connexion');
        // Started 13 hours ago (the clock says 11:00 UTC) and idle since: beyond the maximum duration of a session (12 hours).
        self::service(UserSessionRepository::class)->create(self::owner(), str_repeat('b', 32), '192.0.2.1', 'Ancien navigateur', '2026-11-14T22:00:00Z');

        $crawler = $this->client->request('GET', '/securite/connexion');
        self::assertCount(1, $crawler->filter('#sessions tbody tr'));
        self::assertStringNotContainsString('Ancien navigateur', $crawler->filter('#sessions')->text());
        self::service(UserSessions::class)->purgeExpired();
        self::assertNotContains(str_repeat('b', 32), $this->sessionIds(self::owner()));
        self::assertCount(1, $this->sessionIds(self::owner()));

        $this->submit('/deconnexion', [], '/securite/connexion');
        self::assertSame([], $this->sessionIds(self::owner()));
    }

    private function reauthenticate(): void
    {
        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite/connexion'], '/securite/connexion');
    }

    /** @return list<string> */
    private function sessionIds(UserId $user): array
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $ids = [];
        foreach ($connection->fetchFirstColumn('SELECT id FROM user_sessions WHERE user_id = ? ORDER BY id', [$user->value]) as $id) {
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }
}
