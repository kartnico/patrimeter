<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Calendar;
use Patrimeter\Application\Form\PropertyForm;
use Patrimeter\Application\PropertyPortfolio;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\AccountTypeRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\OwnedResourceVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function Symfony\Component\Translation\t;

/** Properties: net value, creation of the property with its loans, ownership shares, linked loans. */
final class PropertyController extends AbstractController
{
    private const string ID = '\d{1,9}';
    private const string MONTH = '\d{4}-\d{2}';
    private const int LOAN_ROWS = 3;

    /** Loan type of a property type, when it is not that of its level (LEVEL_LOAN_TYPES). */
    private const array LOAN_TYPES = ['residence_secondaire' => 'credit_immo_rs'];

    /** Loan type of the properties of a level. */
    private const array LEVEL_LOAN_TYPES = ['residence' => 'credit_immo_rp', 'immobilier' => 'credit_immo_locatif'];

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly CurrentUser $currentUser,
        private readonly Calendar $calendar,
        private readonly AccountRepository $accounts,
        private readonly AccountTypeRepository $types,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
        private readonly Connection $connection,
    ) {}

    #[Route('/biens', name: 'app_properties', methods: ['GET'])]
    public function index(PropertyPortfolio $portfolio): Response
    {
        $month = $this->calendar->currentMonth();

        return $this->render('property/index.html.twig', ['lines' => $portfolio->lines($this->currentUser->id(), $month), 'month' => $month]);
    }

    #[Route('/biens/nouveau', name: 'app_property_new', methods: ['GET'])]
    public function create(): Response
    {
        return $this->renderForm(null);
    }

    /** The property and its loans are saved together, or nothing is. */
    #[Route('/biens/nouveau', name: 'app_property_create', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $form = PropertyForm::validate(
            $this->validator,
            $request->request->getString('name'),
            $request->request->getString('type_id'),
            $request->request->getString('opened_on'),
            $request->request->getString('value'),
            $request->request->getString('share'),
            self::loanInputs($request),
            $request->getLocale(),
        );
        $type = $this->propertyType($form->typeId);
        if ($type === null) {
            $form->errors['type_id'] = 'Choisissez un type de bien.';
        }
        if (!$form->isValid() || $type === null) {
            return $this->renderForm($form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->saveProperty($form, $type);
        $this->addFlash('success', t('Bien « %name% » créé.', ['%name%' => $form->name]));

        return $this->redirectToRoute('app_properties', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/comptes/{id}/parts', name: 'app_share_save', requirements: ['id' => self::ID], methods: ['POST'])]
    public function saveShare(Request $request, int $id): Response
    {
        $account = $this->findOr404($id);
        $month = Month::tryFromString(trim($request->request->getString('mois')));
        $share = OwnershipShares::parsePercent($request->request->getString('pourcentage'));
        if (!PropertyPortfolio::isProperty($account) || $month === null || $share === null) {
            $this->addFlash('error', t('Mois (AAAA-MM) ou part invalide : un pourcentage supérieur à 0 et au plus 100.'));
        } else {
            $this->shares->save($this->currentUser->id(), $account->id, $month, $share);
            $this->addFlash('success', t('Part de %share% % à partir %of_month%.', ['%share%' => OwnershipShares::formatPercent($share, $request->getLocale()), '%of_month%' => $month->ofLabel($request->getLocale())]));
        }

        return $this->redirectToRoute('app_account_edit', ['id' => $account->id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/comptes/{id}/parts/{month}/supprimer', name: 'app_share_delete', requirements: ['id' => self::ID, 'month' => self::MONTH], methods: ['POST'])]
    public function deleteShare(int $id, string $month): Response
    {
        $account = $this->findOr404($id);
        $effective = Month::tryFromString($month) ?? throw $this->createNotFoundException();
        $this->shares->delete($this->currentUser->id(), $account->id, $effective);
        $this->addFlash('success', t('Part supprimée.'));

        return $this->redirectToRoute('app_account_edit', ['id' => $account->id], Response::HTTP_SEE_OTHER);
    }

    /** Links a loan to a property of the same user (or unlinks it). */
    #[Route('/comptes/{id}/bien', name: 'app_account_link', requirements: ['id' => self::ID], methods: ['POST'])]
    public function link(Request $request, int $id): Response
    {
        $account = $this->findOr404($id);
        $owner = $this->currentUser->id();
        $assetId = $request->request->getInt('asset_id');
        $asset = $assetId === 0 ? null : $this->accounts->find($owner, $assetId);
        if (!$account->isLiability() || ($assetId !== 0 && ($asset === null || !PropertyPortfolio::isProperty($asset)))) {
            $this->addFlash('error', t('Choisissez un bien.'));
        } else {
            $mode = LoanMode::tryFrom($request->request->getString('loan_mode')) ?? LoanMode::Prorata;
            $this->accounts->linkLoan($owner, $account->id, $asset?->id, $mode);
            $this->addFlash('success', $asset === null ? t('Crédit détaché.') : t('Crédit rattaché à « %name% ».', ['%name%' => $asset->name]));
        }

        return $this->redirectToRoute('app_account_edit', ['id' => $account->id], Response::HTTP_SEE_OTHER);
    }

    /** @param array{id: int, code: string, label: string, default_level: string, nature: string} $type */
    private function saveProperty(PropertyForm $form, array $type): void
    {
        $owner = $this->currentUser->id();
        $current = $this->calendar->currentMonth();
        $opened = Month::fromDate($form->openedOn);
        $month = $opened->compare($current) > 0 ? $opened : $current;
        $loanType = $this->loanType($type['code'], $type['default_level']);

        $this->connection->transactional(function () use ($form, $owner, $type, $opened, $month, $loanType): void {
            $assetId = $this->accounts->create($owner, [
                'name' => $form->name, 'type_id' => $type['id'], 'level_code' => $type['default_level'],
                'nature' => Nature::Asset->value, 'opened_on' => $form->openedOn,
            ]);
            $this->statements->save($owner, $assetId, $month, $form->valueCents);
            if ($form->share !== OwnershipShares::FULL) {
                $this->shares->save($owner, $assetId, $opened, $form->share);
            }
            foreach ($form->loans as $loan) {
                $loanId = $this->accounts->create($owner, [
                    'name' => $loan['name'], 'type_id' => $loanType, 'level_code' => $type['default_level'],
                    'nature' => Nature::Liability->value, 'opened_on' => $form->openedOn,
                ]);
                $this->accounts->linkLoan($owner, $loanId, $assetId, $loan['mode']);
                $this->statements->save($owner, $loanId, $month, $loan['cents']);
            }
        });
    }

    /** @return array{id: int, code: string, label: string, default_level: string, nature: string}|null */
    private function propertyType(string $typeId): ?array
    {
        foreach ($this->propertyTypes() as $type) {
            if ((string) $type['id'] === $typeId) {
                return $type;
            }
        }

        return null;
    }

    /** @return list<array{id: int, code: string, label: string, default_level: string, nature: string}> */
    private function propertyTypes(): array
    {
        return array_values(array_filter(
            $this->types->all(),
            static fn(array $t): bool => $t['nature'] === Nature::Asset->value && in_array($t['default_level'], [Level::Residence->value, Level::Immobilier->value], true),
        ));
    }

    /** Loan type of the property: second home, otherwise that of its level (main residence or rental). */
    private function loanType(string $propertyCode, string $level): int
    {
        $code = self::LOAN_TYPES[$propertyCode] ?? self::LEVEL_LOAN_TYPES[$level] ?? null;
        foreach ($this->types->all() as $type) {
            if ($type['code'] === $code && $type['nature'] === Nature::Liability->value) {
                return $type['id'];
            }
        }

        throw new \LogicException("Aucun type de crédit pour le bien $propertyCode.");
    }

    private function findOr404(int $id): Account
    {
        $account = $this->accounts->find($this->currentUser->id(), $id) ?? throw $this->createNotFoundException('Compte introuvable.');
        $this->denyAccessUnlessGranted(OwnedResourceVoter::OWNER, $account);

        return $account;
    }

    /** @return list<array{name: string, amount: string, mode: string}> */
    private static function loanInputs(Request $request): array
    {
        return array_values(array_map(self::loanInput(...), $request->request->all('loans')));
    }

    /** @return array{name: string, amount: string, mode: string} empty line if the input is not an array */
    private static function loanInput(mixed $row): array
    {
        $field = static fn(string $name): string => is_array($row) && isset($row[$name]) && is_string($row[$name]) ? $row[$name] : '';

        return ['name' => $field('name'), 'amount' => $field('amount'), 'mode' => $field('mode')];
    }

    private function renderForm(?PropertyForm $form, int $status = Response::HTTP_OK): Response
    {
        $rows = $form->loanInputs ?? [];
        while (count($rows) < self::LOAN_ROWS) {
            $rows[] = ['name' => '', 'amount' => '', 'mode' => LoanMode::Prorata->value];
        }

        return $this->render('property/form.html.twig', [
            'types' => $this->propertyTypes(),
            'name' => $form->name ?? '',
            'type_id' => $form->typeId ?? '',
            'opened_on' => $form->openedOn ?? $this->calendar->today(),
            'value' => $form->value ?? '',
            'share' => $form->sharePercent ?? '100',
            'loans' => $rows,
            'errors' => $form->errors ?? [],
            'current_month' => $this->calendar->currentMonth(),
        ], new Response(status: $status));
    }
}
