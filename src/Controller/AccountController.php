<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Calendar;
use Patrimeter\Application\Form\AccountForm;
use Patrimeter\Application\PropertyPortfolio;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\BankStatement\BankStatementReader;
use Patrimeter\Domain\BankStatement\UnreadableBankStatement;
use Patrimeter\Domain\DateInput;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\AccountTypeRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\OwnedResourceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function Symfony\Component\Translation\t;

/**
 * Management of the accounts and loans, and of their statements.
 *
 * All reads and writes go through the signed-in owner (CurrentUser); an account of another user cannot be
 * found (404), and the OwnedResourceVoter voter checks again the owner of each account read.
 */
final class AccountController extends AbstractController
{
    private const string ID = '\d{1,9}';
    private const string MONTH = '\d{4}-\d{2}';
    /** Bank statement read on an account page: 2 MB at most (also the limit of nginx for this address). */
    private const int STATEMENT_FILE_MAX_BYTES = 2 * 1024 * 1024;
    private const array FORM_FIELDS = ['name', 'type_id', 'level_code', 'nature', 'opened_on', 'initial_amount'];
    /** Properties are created on their own screen (PropertyController), with their share and their loans. */
    private const string PROPERTY_ELSEWHERE = 'Un bien immobilier (résidence, location, SCPI…) se crée depuis Biens immobiliers, « Nouveau bien ».';

    public function __construct(
        private readonly Calendar $calendar,
        private readonly AccountRepository $accounts,
        private readonly AccountTypeRepository $types,
        private readonly StatementRepository $statements,
        private readonly Connection $connection,
        private readonly CurrentUser $currentUser,
        private readonly OwnershipShareRepository $shares,
        private readonly PropertyPortfolio $portfolio,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/comptes', name: 'app_accounts', methods: ['GET'])]
    public function index(): Response
    {
        $owner = $this->currentUser->id();
        $month = $this->calendar->currentMonth();
        $valuation = $this->statements->valuation($owner);

        $active = [];
        $archived = [];
        foreach ($this->accounts->all($owner) as $account) {
            $line = ['account' => $account, 'value' => $valuation->valueAt($account->id, $month)];
            if ($account->isArchived()) {
                $archived[] = $line;
            } else {
                $active[$account->level->value][] = $line;
            }
        }

        return $this->render('account/index.html.twig', ['active' => $active, 'archived' => $archived, 'month' => $month]);
    }

    #[Route('/comptes/nouveau', name: 'app_account_new', methods: ['GET'])]
    public function create(): Response
    {
        return $this->renderForm(null, [
            'name' => '', 'type_id' => '', 'level_code' => '', 'nature' => '',
            'opened_on' => $this->calendar->today(), 'initial_amount' => '',
        ], []);
    }

    #[Route('/comptes/nouveau', name: 'app_account_create', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $form = $this->form($request, true);
        if ($form->isValid() && self::makesAProperty($form->data)) {
            $form->errors['type_id'] = self::PROPERTY_ELSEWHERE;
        }
        if (!$form->isValid()) {
            return $this->renderForm(null, $form->raw, $form->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $owner = $this->currentUser->id();
        $this->connection->transactional(function () use ($form, $owner): void {
            $id = $this->accounts->create($owner, $form->data);
            if ($form->initialCents !== null) {
                // Initial balance: current month, or opening month if later.
                $month = $this->calendar->currentMonth();
                $opened = Month::fromDate($form->data['opened_on']);
                $this->statements->save($owner, $id, $opened->compare($month) > 0 ? $opened : $month, $form->initialCents);
            }
        });
        $this->addFlash('success', t('Compte « %name% » créé.', ['%name%' => $form->data['name']]));

        return $this->redirectToRoute('app_accounts', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/comptes/{id}', name: 'app_account_edit', requirements: ['id' => self::ID], methods: ['GET'])]
    public function edit(int $id): Response
    {
        return $this->renderAccount($this->findOr404($id));
    }

    #[Route('/comptes/{id}', name: 'app_account_update', requirements: ['id' => self::ID], methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $account = $this->findOr404($id);
        $form = $this->form($request, false);
        if ($form->isValid() && $account->closedOn !== null && $form->data['opened_on'] > $account->closedOn) {
            $form->errors['opened_on'] = 'La date d\'ouverture doit précéder la date de clôture.';
        }
        if ($form->isValid()) {
            $form->errors = $this->portfolio->conflicts($this->currentUser->id(), $account, $form->data);
        }
        // After the conflicts, more precise (a loan linked to a property must first be unlinked from it).
        if ($form->isValid() && !PropertyPortfolio::isProperty($account) && self::makesAProperty($form->data)) {
            $form->errors['type_id'] = self::PROPERTY_ELSEWHERE;
        }
        if (!$form->isValid()) {
            return $this->renderForm($account, $form->raw, $form->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->accounts->update($this->currentUser->id(), $account->id, $form->data);
        $this->addFlash('success', t('Compte mis à jour.'));

        return $this->redirectToAccount($account);
    }

    #[Route('/comptes/{id}/archiver', name: 'app_account_archive', requirements: ['id' => self::ID], methods: ['POST'])]
    public function archive(Request $request, int $id): Response
    {
        $account = $this->findOr404($id);
        $closedOn = trim($request->request->getString('closed_on'));
        if (!DateInput::isValid($closedOn) || $closedOn < $account->openedOn) {
            $this->addFlash('error', t('Date de clôture invalide (elle doit suivre la date d\'ouverture).'));

            return $this->redirectToAccount($account);
        }
        $this->accounts->archive($this->currentUser->id(), $account->id, $closedOn);
        $this->addFlash('success', t('Compte « %name% » archivé.', ['%name%' => $account->name]));

        return $this->redirectToRoute('app_accounts', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/comptes/{id}/reactiver', name: 'app_account_unarchive', requirements: ['id' => self::ID], methods: ['POST'])]
    public function unarchive(int $id): Response
    {
        $account = $this->findOr404($id);
        $this->accounts->unarchive($this->currentUser->id(), $account->id);
        $this->addFlash('success', t('Compte réactivé.'));

        return $this->redirectToAccount($account);
    }

    #[Route('/comptes/{id}/supprimer', name: 'app_account_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function destroy(int $id): Response
    {
        $account = $this->findOr404($id);
        $this->accounts->delete($this->currentUser->id(), $account->id);
        $this->addFlash('success', t('Compte « %name% » supprimé avec son historique.', ['%name%' => $account->name]));

        return $this->redirectToRoute('app_accounts', status: Response::HTTP_SEE_OTHER);
    }

    /** Adding or correcting a statement for any month (filling in the history). */
    #[Route('/comptes/{id}/releves', name: 'app_statement_save', requirements: ['id' => self::ID], methods: ['POST'])]
    public function saveStatement(Request $request, int $id): Response
    {
        $account = $this->findOr404($id);
        $month = Month::tryFromString(trim($request->request->getString('mois')));
        if ($month === null) {
            $this->addFlash('error', t('Mois invalide (AAAA-MM).'));

            return $this->redirectToAccount($account);
        }
        if (!$account->isActiveIn($month)) {
            $this->addFlash('error', t('Le compte n\'est pas ouvert en %month%.', ['%month%' => $month->label($request->getLocale())]));

            return $this->redirectToAccount($account);
        }
        try {
            $cents = Money::parse($request->request->getString('montant'), $request->getLocale());
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', t($e->getMessage()));

            return $this->redirectToAccount($account);
        }
        $this->statements->save($this->currentUser->id(), $account->id, $month, $cents);
        $this->addFlash('success', t('Relevé %of_month% enregistré.', ['%of_month%' => $month->ofLabel($request->getLocale())]));

        return $this->redirectToAccount($account);
    }

    /**
     * Bank statement exported by the bank (OFX, CAMT.053): its closing balance fills in the statement form of the page,
     * for its month, to check and save as any statement. Nothing is saved here, nor kept anywhere.
     */
    #[Route('/comptes/{id}/releves/fichier', name: 'app_statement_file', requirements: ['id' => self::ID], methods: ['POST'])]
    public function readStatementFile(Request $request, int $id, #[MapUploadedFile(name: 'releve')] ?UploadedFile $file = null): Response
    {
        $account = $this->findOr404($id);
        try {
            if ($file === null || !$file->isValid() || $file->getSize() > self::STATEMENT_FILE_MAX_BYTES) {
                throw new UnreadableBankStatement('Fichier illisible : choisissez un relevé OFX ou CAMT.053 exporté par votre banque.');
            }
            $balance = BankStatementReader::read($file->getContent());
        } catch (UnreadableBankStatement $e) {
            return $this->renderAccount($account, ['statement_file_error' => t($e->getMessage())], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $month = Month::fromString(substr($balance->date, 0, 7));
        if (!$account->isActiveIn($month)) {
            return $this->renderAccount($account, ['statement_file_error' => t('Le compte n\'est pas ouvert en %month%.', ['%month%' => $month->label($request->getLocale())])], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // A loan's statement shows the debt as a negative balance; Patrimeter keeps the capital owed, positive.
        $cents = $account->isLiability() ? -$balance->cents : $balance->cents;

        return $this->renderAccount($account, ['statement_proposal' => ['month' => $month, 'date' => $balance->date, 'cents' => $cents]]);
    }

    #[Route(
        '/comptes/{id}/releves/{month}/supprimer',
        name: 'app_statement_delete',
        requirements: ['id' => self::ID, 'month' => self::MONTH],
        methods: ['POST'],
    )]
    public function deleteStatement(Request $request, int $id, string $month): Response
    {
        $account = $this->findOr404($id);
        $statementMonth = Month::tryFromString($month) ?? throw $this->createNotFoundException();
        $this->statements->delete($this->currentUser->id(), $account->id, $statementMonth);
        $this->addFlash('success', t('Relevé %of_month% supprimé.', ['%of_month%' => $statementMonth->ofLabel($request->getLocale())]));

        return $this->redirectToAccount($account);
    }

    /** Account of the signed-in owner; that of another user cannot be found. */
    private function findOr404(int $id): Account
    {
        $account = $this->accounts->find($this->currentUser->id(), $id) ?? throw $this->createNotFoundException('Compte introuvable.');
        $this->denyAccessUnlessGranted(OwnedResourceVoter::OWNER, $account);

        return $account;
    }

    /**
     * Page of an existing account, its form filled with what it holds.
     *
     * @param array<string, mixed> $extra other variables of the page (statement read from a file…)
     */
    private function renderAccount(Account $account, array $extra = [], int $status = Response::HTTP_OK): Response
    {
        return $this->renderForm($account, [
            'name' => $account->name,
            'type_id' => (string) $account->typeId,
            'level_code' => $account->level->value,
            'nature' => $account->nature->value,
            'opened_on' => $account->openedOn,
            'initial_amount' => '',
        ], [], $status, $extra);
    }

    private function redirectToAccount(Account $account): Response
    {
        return $this->redirectToRoute('app_account_edit', ['id' => $account->id], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     * @param array<string, mixed>  $extra  other variables of the page
     */
    private function renderForm(?Account $account, array $values, array $errors, int $status = Response::HTTP_OK, array $extra = []): Response
    {
        $response = $this->render('account/form.html.twig', $extra + [
            'account' => $account,
            'values' => $values,
            'errors' => $errors,
            // An existing property keeps all the types; otherwise, no property type (PROPERTY_ELSEWHERE).
            'types' => $account !== null && PropertyPortfolio::isProperty($account) ? $this->types->all() : array_values(array_filter(
                $this->types->all(),
                static fn(array $t): bool => !PropertyPortfolio::isPropertyKind(Level::from($t['default_level']), Nature::from($t['nature'])),
            )),
            'levels' => Level::ordered(),
            'statements' => $account === null ? [] : $this->statementLines($account),
            'is_property' => $account !== null && PropertyPortfolio::isProperty($account),
            'share_lines' => $account === null ? [] : $this->shareLines($account),
            'properties' => array_values(array_filter($this->accounts->all($this->currentUser->id()), PropertyPortfolio::isProperty(...))),
            'today' => $this->calendar->today(),
            'current_month' => $this->calendar->currentMonth(),
        ]);

        return $response->setStatusCode($status);
    }

    /** @return list<array{month: Month, share: int}> shares of the property, from the most recent to the oldest */
    private function shareLines(Account $account): array
    {
        $lines = [];
        foreach (array_reverse($this->shares->all($this->currentUser->id())[$account->id] ?? [], true) as $month => $share) {
            $lines[] = ['month' => Month::fromString($month), 'share' => $share];
        }

        return $lines;
    }

    /** @return list<array{month: Month, cents: int}> statements of the account, from the most recent to the oldest */
    private function statementLines(Account $account): array
    {
        $lines = [];
        foreach ($this->statements->forAccount($this->currentUser->id(), $account->id) as $month => $cents) {
            $lines[] = ['month' => Month::fromString($month), 'cents' => $cents];
        }

        return $lines;
    }

    /** @param array{level_code: string, nature: string, ...} $data */
    private static function makesAProperty(array $data): bool
    {
        return PropertyPortfolio::isPropertyKind(Level::from($data['level_code']), Nature::from($data['nature']));
    }

    private function form(Request $request, bool $withInitialAmount): AccountForm
    {
        $input = [];
        foreach (self::FORM_FIELDS as $field) {
            $input[$field] = $request->request->getString($field);
        }

        return AccountForm::validate($this->validator, $input, $this->types->all(), $withInitialAmount, $request->getLocale());
    }
}
