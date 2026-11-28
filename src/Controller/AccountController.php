<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\AccountBook;
use Patrimeter\Application\Calendar;
use Patrimeter\Application\Form\AccountForm;
use Patrimeter\Application\Refused;
use Patrimeter\Application\ValidationFailed;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\AccountTypeRepository;
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
 * Pages of the accounts and loans, and of their statements: reads the request, lets AccountBook apply the rules, and
 * shows the outcome.
 *
 * All reads and writes go through the signed-in owner (CurrentUser); an account of another user cannot be found
 * (404), and the OwnedResourceVoter voter checks again the owner of each account read.
 */
final class AccountController extends AbstractController
{
    private const string ID = '\d{1,9}';
    private const string MONTH = '\d{4}-\d{2}';
    /** Bank statement read on an account page: 2 MB at most (also the limit of nginx for this address). */
    private const int STATEMENT_FILE_MAX_BYTES = 2 * 1024 * 1024;
    private const array FORM_FIELDS = ['name', 'type_id', 'level_code', 'nature', 'opened_on', 'initial_amount'];

    public function __construct(
        private readonly AccountBook $book,
        private readonly AccountRepository $accounts,
        private readonly AccountTypeRepository $types,
        private readonly Calendar $calendar,
        private readonly CurrentUser $currentUser,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/comptes', name: 'app_accounts', methods: ['GET'])]
    public function index(): Response
    {
        $month = $this->calendar->currentMonth();

        return $this->render('account/index.html.twig', $this->book->overview($this->owner(), $month) + ['month' => $month]);
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
        if (!$form->isValid()) {
            return $this->renderForm(null, $form->raw, $form->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->book->create($this->owner(), $form);
        } catch (ValidationFailed $failure) {
            return $this->renderForm(null, $form->raw, $failure->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
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
        if (!$form->isValid()) {
            return $this->renderForm($account, $form->raw, $form->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->book->update($this->owner(), $account, $form);
        } catch (ValidationFailed $failure) {
            return $this->renderForm($account, $form->raw, $failure->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', t('Compte mis à jour.'));

        return $this->redirectToAccount($account);
    }

    #[Route('/comptes/{id}/archiver', name: 'app_account_archive', requirements: ['id' => self::ID], methods: ['POST'])]
    public function archive(Request $request, int $id): Response
    {
        $account = $this->findOr404($id);
        try {
            $this->book->archive($this->owner(), $account, $request->request->getString('closed_on'));
        } catch (Refused $refused) {
            $this->addFlash('error', $refused->reason);

            return $this->redirectToAccount($account);
        }
        $this->addFlash('success', t('Compte « %name% » archivé.', ['%name%' => $account->name]));

        return $this->redirectToRoute('app_accounts', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/comptes/{id}/reactiver', name: 'app_account_unarchive', requirements: ['id' => self::ID], methods: ['POST'])]
    public function unarchive(int $id): Response
    {
        $account = $this->findOr404($id);
        $this->book->unarchive($this->owner(), $account);
        $this->addFlash('success', t('Compte réactivé.'));

        return $this->redirectToAccount($account);
    }

    #[Route('/comptes/{id}/supprimer', name: 'app_account_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function destroy(int $id): Response
    {
        $account = $this->findOr404($id);
        $this->book->delete($this->owner(), $account);
        $this->addFlash('success', t('Compte « %name% » supprimé avec son historique.', ['%name%' => $account->name]));

        return $this->redirectToRoute('app_accounts', status: Response::HTTP_SEE_OTHER);
    }

    /** Adding or correcting a statement for any month (filling in the history). */
    #[Route('/comptes/{id}/releves', name: 'app_statement_save', requirements: ['id' => self::ID], methods: ['POST'])]
    public function saveStatement(Request $request, int $id): Response
    {
        $account = $this->findOr404($id);
        try {
            $month = $this->book->saveStatement($this->owner(), $account, $request->request->getString('mois'), $request->request->getString('montant'), $request->getLocale());
            $this->addFlash('success', t('Relevé %of_month% enregistré.', ['%of_month%' => $month->ofLabel($request->getLocale())]));
        } catch (Refused $refused) {
            $this->addFlash('error', $refused->reason);
        }

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
        $content = $file !== null && $file->isValid() && $file->getSize() <= self::STATEMENT_FILE_MAX_BYTES ? $file->getContent() : null;
        try {
            $proposal = $this->book->readStatement($account, $content, $request->getLocale());
        } catch (Refused $refused) {
            return $this->renderAccount($account, ['statement_file_error' => $refused->reason], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderAccount($account, ['statement_proposal' => $proposal]);
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
        $this->book->deleteStatement($this->owner(), $account, $statementMonth);
        $this->addFlash('success', t('Relevé %of_month% supprimé.', ['%of_month%' => $statementMonth->ofLabel($request->getLocale())]));

        return $this->redirectToAccount($account);
    }

    private function owner(): UserId
    {
        return $this->currentUser->id();
    }

    /** Account of the signed-in owner; that of another user cannot be found. */
    private function findOr404(int $id): Account
    {
        $account = $this->accounts->find($this->owner(), $id) ?? throw $this->createNotFoundException('Compte introuvable.');
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
        $page = $extra + ['values' => $values, 'errors' => $errors] + $this->book->formView($this->owner(), $account);

        return $this->render('account/form.html.twig', $page)->setStatusCode($status);
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
