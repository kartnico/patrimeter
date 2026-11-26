<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Family account: import of each member's export file (two at most, the new import replaces the previous one), views
 * of the family, of a member or of this account (dashboard, history, report, budget), read-only, encryption.
 */
final class FamilyTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testImportsAMemberAndShowsTheFamilyByDefault(): void
    {
        $this->import(null, 'Alex', self::memberExport('Livret d\'Alex', 1_000_000));
        self::assertResponseRedirects('/famille', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Données de Alex importées', $this->flashes());
        self::assertSelectorTextContains('section.family-member h2', 'Alex');

        // Family account with an account of its own (a child's): the family adds everything up.
        $own = self::createAccount('Livret de Léa', 'livret_a', '2020-01-01');
        self::service(StatementRepository::class)->save(self::owner(), $own, Month::fromString('2026-11'), 50_000);
        $crawler = $this->client->request('GET', '/');

        self::assertSame("10\u{202F}500,00\u{00A0}€", trim($crawler->filter('.summary-total')->text()));
        $choices = $crawler->filter('.perimeter-nav a')->each(static fn($link): string => trim($link->text()));
        self::assertSame(['Toute la famille', 'Alex', 'Ce compte'], $choices);
        self::assertSame('Toute la famille', trim($crawler->filter('.perimeter-nav [aria-current="true"]')->text()));
        // Imported account: read, without an edit link, with the name of its member.
        self::assertStringContainsString('Livret d\'Alex · Alex', preg_replace('/\s+/u', ' ', $crawler->filter('.levels-card')->text()) ?? '');
        self::assertCount(1, $crawler->filter('.levels-card a[href^="/comptes/"]'), 'seul le compte propre se modifie');
    }

    public function testThePerimeterIsKeptFromPageToPage(): void
    {
        $member = self::createFamilyMember(self::owner(), 'Sam', 'Livret de Sam', 2_000_000);
        $own = self::createAccount('Compte joint', 'compte_courant', '2020-01-01');
        self::service(StatementRepository::class)->save(self::owner(), $own, Month::fromString('2026-11'), 300_000);

        $crawler = $this->client->request('GET', "/?perimetre=membre-$member&mois=2026-11");
        self::assertSame("20\u{202F}000,00\u{00A0}€", trim($crawler->filter('.summary-total')->text()));
        self::assertSame('/?perimetre=compte&mois=2026-11', $crawler->filter('.perimeter-nav a')->last()->attr('href'));

        // The budget and the report keep the chosen perimeter.
        $this->client->request('GET', '/budget');
        self::assertSelectorTextContains('.perimeter-nav [aria-current="true"]', 'Sam');
        $this->client->request('GET', '/rapport');
        self::assertSelectorTextContains('#report-title', 'Patrimoine de Sam');

        $crawler = $this->client->request('GET', '/?perimetre=compte');
        self::assertSame("3\u{202F}000,00\u{00A0}€", trim($crawler->filter('.summary-total')->text()));
        // An unknown or malformed perimeter brings back to the family.
        $crawler = $this->client->request('GET', '/?perimetre=membre-999999');
        self::assertSelectorTextContains('.perimeter-nav [aria-current="true"]', 'Ce compte');
        $this->client->request('GET', '/?perimetre=famille');
        $crawler = $this->client->request('GET', '/?perimetre=../etc');
        self::assertSame("23\u{202F}000,00\u{00A0}€", trim($crawler->filter('.summary-total')->text()));
    }

    public function testANewImportReplacesThePreviousOne(): void
    {
        $member = self::createFamilyMember(self::owner(), 'Alex', 'Ancien livret', 1_000_000);

        $this->import($member, null, self::memberExport('Nouveau livret', 1_500_000));
        self::assertResponseRedirects('/famille', Response::HTTP_SEE_OTHER);

        $crawler = $this->client->request('GET', '/?perimetre=famille');
        self::assertSame("15\u{202F}000,00\u{00A0}€", trim($crawler->filter('.summary-total')->text()));
        self::assertStringNotContainsString('Ancien livret', $this->body());
        $this->client->request('GET', '/famille');
        self::assertSelectorTextContains('section.family-member h2', 'Alex', 'le nom est gardé');
    }

    public function testTwoMembersAtMostAndInvalidFilesAreRefused(): void
    {
        self::createFamilyMember(self::owner(), 'Alex', 'Livret A', 100_000);
        self::createFamilyMember(self::owner(), 'Sam', 'Livret B', 100_000);
        $crawler = $this->client->request('GET', '/famille');
        self::assertCount(0, $crawler->filter('#member-new-title'), 'plus de formulaire d\'ajout');

        $this->import(null, 'Charlie', self::memberExport('Livret C', 100_000));
        $this->client->followRedirect();
        self::assertStringContainsString('Rien n\'a été importé. Un compte famille réunit deux membres au plus.', $this->flashes());

        $members = $crawler->filter('section.family-member form[action$="/supprimer"]');
        $this->client->request('POST', (string) $members->first()->attr('action'), ['_csrf' => $this->csrfToken('/famille')]);
        $this->client->followRedirect();
        self::assertStringContainsString('Membre « Alex » retiré', $this->flashes());

        // Statement earlier than the opening of the savings account (2024).
        $this->import(null, 'Dom', self::memberExport('Livret D', 100_000, null, 'Dom', '2019-01'));
        $this->client->followRedirect();
        self::assertStringContainsString('relevé invalide ou hors de la période d\'ouverture', $this->flashes());
        $this->import(null, '', self::memberExport('Livret E', 100_000));
        $this->client->followRedirect();
        self::assertStringContainsString('Indiquez un nom de 1 à 40 caractères.', $this->flashes());
        $this->client->request('POST', '/famille/membres', ['_csrf' => $this->csrfToken('/famille'), 'name' => 'Eve']);
        $this->client->followRedirect();
        self::assertStringContainsString('Choisissez un fichier d\'export de Patrimeter', $this->flashes());
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM family_members'));
    }

    public function testFamilyBudgetAddsUpTheMembersBudgets(): void
    {
        self::createFamilyMember(self::owner(), 'Alex', 'Livret d\'Alex', 1_000_000, 300_000);
        $sam = self::memberExport('Livret de Sam', 500_000, 200_000, 'Sam');
        $this->import(null, 'Sam', $sam);

        $crawler = $this->client->request('GET', '/budget?perimetre=famille&mois=2026-11');
        self::assertResponseIsSuccessful();
        // Incomes: €3,000 + €2,000; each €1,000 of rent and €200 of savings.
        $figures = $crawler->filter('.budget-figures dd')->each(static fn($value): string => str_replace(["\u{202F}", "\u{00A0}"], ' ', trim($value->text())));
        self::assertSame(['5 000,00 €', '2 000,00 €', '400,00 €', '2 600,00 €'], $figures, 'revenus, dépenses, épargne, disponible');
        self::assertSelectorNotExists('#budget-joint-title', 'pas de compte commun : chacun a saisi sa part');
        self::assertSelectorNotExists('a[href^="/budget/saisie"]', 'lecture seule');
        self::assertSelectorNotExists('#budget-delete-title');
        self::assertSame(['Alex', 'Sam'], $crawler->filter('.budget-adults thead th.num')->each(static fn($cell): string => trim($cell->text())));
        // Léa, child of both members: a single child, whose cost brings both budgets together.
        self::assertCount(1, $crawler->filter('#budget-children-title + .sheet-lead + table tbody tr'));
        self::assertStringContainsString('400,00', $crawler->filter('#budget-children-title')->ancestors()->first()->text());
        // Savings payments to the imported savings accounts: without an edit link.
        self::assertCount(2, $crawler->filter('.savings-item'));
        self::assertCount(0, $crawler->filter('.savings-item a.savings-account'));

        // This account, with an account but without a budget: creation of its own budget offered.
        self::createAccount('Livret de Léa', 'livret_a', '2020-01-01');
        $this->client->request('GET', '/budget?perimetre=compte');
        self::assertSelectorTextContains('h1', 'Budget du foyer');
        self::assertSelectorTextContains('.perimeter-nav [aria-current="true"]', 'Ce compte');
    }

    public function testDashboardRecommendationsUseTheFamilyBudget(): void
    {
        self::createFamilyMember(self::owner(), 'Alex', 'Livret d\'Alex', 1_000_000, 300_000);

        $crawler = $this->client->request('GET', '/?mois=2026-11');

        // €10,000 of emergency savings for €1,000 of spending: 10 months, more than twice the target of 3 months.
        self::assertSelectorExists('.advice-card svg.precaution-gauge.is-surplus');
        self::assertStringContainsString('10 mois', str_replace("\u{202F}", ' ', $crawler->filter('.advice-card')->text()));
        self::assertSelectorNotExists('.advice-card a[href="/budget/foyer"]', 'liens de saisie réservés à ce compte');
    }

    public function testImportedDataIsEncryptedAndOwnExportLeavesItOut(): void
    {
        self::createFamilyMember(self::owner(), 'Membre-Clair-4f1c', 'Livret-Clair-8e2a', 1_234_567, 300_000);

        $rows = self::service(Connection::class)->fetchAllAssociative('SELECT * FROM family_members');
        self::assertCount(1, $rows);
        $dump = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach (['Membre-Clair-4f1c', 'Livret-Clair-8e2a', '1234567', 'Logement'] as $clear) {
            self::assertStringNotContainsString($clear, $dump);
        }
    }

    public function testFamilyPageExplainsTheMonthlyImport(): void
    {
        $crawler = $this->client->request('GET', '/famille');

        self::assertSelectorTextContains('h1', 'Membres de la famille');
        self::assertSelectorExists('form[action="/famille/membres"][enctype="multipart/form-data"] input[type="file"][name="fichier"]');
        self::assertCount(4, $crawler->filter('.family-steps li'));
        self::assertSelectorNotExists('.perimeter-nav');

        self::createFamilyMember(self::owner(), 'Alex', 'Livret', 100_000);
        $crawler = $this->client->request('GET', '/famille');
        // File of 1 November, import of the 15th: up to date for November.
        self::assertCount(0, $crawler->filter('.family-member .advice.is-warning'));
        self::assertStringContainsString('fichier du 01/11/2026', preg_replace('/\s+/u', ' ', $crawler->filter('.family-member')->text()) ?? '');
    }

    /** @param array<string, mixed> $export */
    private function import(?int $member, ?string $name, array $export): void
    {
        $file = tempnam(sys_get_temp_dir(), 'patrimeter-member');
        self::assertIsString($file);
        file_put_contents($file, json_encode($export, JSON_THROW_ON_ERROR));
        $fields = ['_csrf' => $this->csrfToken('/famille')] + ($name === null ? [] : ['name' => $name]);
        $this->client->request('POST', $member === null ? '/famille/membres' : "/famille/membres/$member", $fields, ['fichier' => new UploadedFile($file, 'export.json', 'application/json', null, true)]);
    }
}
