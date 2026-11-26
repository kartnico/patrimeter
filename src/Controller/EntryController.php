<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Calendar;
use Patrimeter\Application\Game\Game;
use Patrimeter\Application\Goals\SavingsGoals;
use Patrimeter\Application\MonthlyEntry;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

/**
 * Monthly entry: all the active accounts of the month, prefilled with the last known balance. Only the signed-in
 * owner's accounts are read and saved; a submitted account identifier that is not among them is ignored.
 */
final class EntryController extends AbstractController
{
    public function __construct(
        private readonly Calendar $calendar,
        private readonly AccountRepository $accounts,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
        private readonly CurrentUser $currentUser,
        private readonly TranslatorInterface $translator,
        private readonly MonthlyEntry $entry,
        private readonly SavingsGoals $goals,
        private readonly Game $game,
    ) {}

    #[Route('/saisie', name: 'app_entry', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $current = $this->calendar->currentMonth();
        $month = MonthQuery::from($request, $current);
        // Up to next month: its balances can be prepared in advance.
        // No account yet (or a month after the next one): the current month, the one where the first accounts are created.
        if ($month->compare($current->next()) > 0 || (!$month->equals($current) && $this->accounts->all($this->currentUser->id()) === [])) {
            return $this->redirectToRoute('app_entry', status: Response::HTTP_SEE_OTHER);
        }
        // Copy of an entry: the form takes the balances of another month, saved only on submission.
        $source = Month::tryFromString($request->query->getString('depuis'));
        if ($source !== null && ($source->equals($month) || $source->compare($current->next()) > 0)) {
            $source = null;
        }

        return $this->renderEntry($month, null, [], copyFrom: $source);
    }

    /** All-or-nothing validation: a single invalid amount and nothing is saved. */
    #[Route('/saisie', name: 'app_entry_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $month = Month::tryFromString($request->request->getString('mois'));
        if ($month === null || $month->compare($this->calendar->currentMonth()->next()) > 0) {
            $this->addFlash('error', t('Mois invalide.'));

            return $this->redirectToRoute('app_entry', status: Response::HTTP_SEE_OTHER);
        }

        $owner = $this->currentUser->id();
        $inputs = self::amountInputs($request);
        // Copy from a later month: the accounts opened after this month, ticked by the form, are carried into it.
        $source = Month::tryFromString($request->request->getString('depuis'));
        $later = $source !== null ? $this->entry->openedAfter($owner, $month, $source) : [];
        $requested = self::ids($request->request->all('advance'));
        $accounts = $this->accounts->activeIn($owner, $month);
        foreach ($later as $id => $account) {
            if (in_array($id, $requested, true)) {
                $accounts[] = $account;
            }
        }
        $values = [];
        $advance = [];
        $errors = [];
        foreach ($accounts as $account) {
            // Account missing from the form (created since it was shown): its statement does not change.
            if (!array_key_exists($account->id, $inputs)) {
                continue;
            }
            $raw = trim($inputs[$account->id]);
            if ($raw === '') {
                // Account carried over from a later month, but emptied: it stays open at its date.
                if (!isset($later[$account->id])) {
                    $values[$account->id] = null;
                }
                continue;
            }
            if (isset($later[$account->id])) {
                $advance[] = $account->id;
            }
            try {
                // Loan: "−167 612,87" (as shown) and "167 612,87" mean the same outstanding principal.
                $cents = Money::parse($raw, $request->getLocale());
                $values[$account->id] = $account->isLiability() ? abs($cents) : $cents;
            } catch (\InvalidArgumentException $e) {
                $errors[$account->id] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            return $this->renderEntry($month, $inputs, $errors, Response::HTTP_UNPROCESSABLE_ENTITY, $source);
        }

        // Savings goals whose status changes with this entry: told right away (the dashboard shows the rest).
        $before = $this->goals->progress($owner, $month);
        $this->entry->save($owner, $month, $values, $advance);
        $count = count(array_filter($values, static fn(?int $v): bool => $v !== null));
        $this->addFlash('success', t('Saisie %of_month% enregistrée (%count% compte).|Saisie %of_month% enregistrée (%count% comptes).', ['%of_month%' => $month->ofLabel($request->getLocale()), '%count%' => $count]));
        foreach (SavingsGoals::changes($before, $before === [] ? [] : $this->goals->progress($owner, $month)) as $change) {
            $this->addFlash('info', t('Objectif « %name% » : %from% → %to%.', [
                '%name%' => $change['name'],
                '%from%' => t($change['from']->label()),
                '%to%' => t($change['to']->label()),
            ]));
        }

        // Stars earned with this entry (challenges on): announced right away, kept for good.
        foreach ($this->game->newStars($owner) as $earned) {
            $this->addFlash('success', t('Nouvelle étoile, défi « %challenge% » : %count% sur %max%.', [
                '%challenge%' => t($earned['challenge']->title()),
                '%count%' => $earned['stars'],
                '%max%' => $earned['max'],
            ]));
        }

        return $this->redirectToRoute('app_dashboard', ['mois' => $month->toString()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Account identifiers submitted (boxes of the form).
     *
     * @param array<mixed> $values
     *
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        $ids = [];
        foreach (array_keys($values) as $key) {
            if (is_string($values[$key]) && ctype_digit($values[$key])) {
                $ids[] = (int) $values[$key];
            }
        }

        return $ids;
    }

    /**
     * Amounts submitted, indexed by account identifier.
     *
     * @return array<int, string>
     */
    private static function amountInputs(Request $request): array
    {
        $amounts = $request->request->all('amounts');
        $inputs = [];
        foreach (array_keys($amounts) as $id) {
            if (is_int($id) && is_string($amounts[$id])) {
                $inputs[$id] = $amounts[$id];
            }
        }

        return $inputs;
    }

    /**
     * @param array<int, string>|null $submitted values submitted (shown again on error)
     * @param array<int, string>      $errors
     */
    private function renderEntry(Month $month, ?array $submitted, array $errors, int $status = Response::HTTP_OK, ?Month $copyFrom = null): Response
    {
        $locale = $this->translator->getLocale();
        $copiedCount = 0;
        $owner = $this->currentUser->id();
        // Every account read once (decrypted): those of the month for the form, all of them for last month's net worth.
        $allAccounts = $this->accounts->all($owner);
        $accounts = array_values(array_filter($allAccounts, static fn(Account $account): bool => $account->isActiveIn($month)));
        // Copy from a later month: the accounts opened after this month are offered, their opening will be moved back.
        $later = $copyFrom !== null ? $this->entry->openedAfter($owner, $month, $copyFrom) : [];
        $accounts = [...$accounts, ...array_values($later)];
        $valuation = $this->statements->valuation($owner);
        // Balances of the first accounts, entered roughly on the welcome pages: "to refine" until saved again.
        $approximate = $this->statements->approximate($owner);
        $shares = new OwnershipShares($this->shares->all($owner));
        $previousMonth = $month->previous();

        $groups = [];
        $totals = [];
        // Levels whose balances are all entered this month (none copied, none rejected): folded in the form.
        $pending = [];
        $net = 0;
        $carriedCount = 0;
        $enteredCount = 0;
        foreach ($accounts as $account) {
            $value = $valuation->valueAt($account->id, $month);
            if ($value !== null) {
                $value->carried ? ++$carriedCount : ++$enteredCount;
            }
            $copied = $copyFrom !== null ? $valuation->valueAt($account->id, $copyFrom) : null;
            if ($copied !== null) {
                ++$copiedCount;
            }
            $shown = $copied ?? $value;
            $input = $submitted !== null
                ? ($submitted[$account->id] ?? '')
                : ($shown !== null ? self::input($account, $shown->cents, $locale) : '');
            $share = $shares->retainedShare($account, $month);
            // Value retained from the amount shown (share applied, liability subtracted): level totals and month's net.
            $retained = self::retained($account, $input, $share, $locale);
            $level = $account->level->value;
            $pending[$level] = ($pending[$level] ?? false) || $value === null || $value->carried || $copied !== null || isset($errors[$account->id]);
            $totals[$level] = ($totals[$level] ?? 0) + ($retained ?? 0);
            $net += $retained ?? 0;
            $groups[$level][] = [
                'account' => $account,
                'value' => $value,
                'previous' => $valuation->valueAt($account->id, $previousMonth),
                'copied' => $copied !== null,
                'input' => $input,
                'share' => $share,
                'error' => $errors[$account->id] ?? null,
                'advance' => isset($later[$account->id]),
                'approximate' => $value !== null && isset($approximate[$account->id][$value->sourceMonth->toString()]),
            ];
        }

        // Net worth of the previous month, for the change shown while typing; none before the first balance.
        // From the statements and shares already read for the form: nothing is decrypted twice.
        $previousSnapshot = $accounts !== [] ? (new NetWorthCalculator($allAccounts, $valuation, $shares))->snapshot($previousMonth) : null;
        $previousNet = $previousSnapshot?->hasData() === true ? $previousSnapshot->net() : null;

        $current = $this->calendar->currentMonth();
        $response = $this->render('entry/index.html.twig', [
            'month' => $month,
            'groups' => $groups,
            'entered_count' => $enteredCount,
            'carried_count' => $carriedCount,
            'account_count' => count($accounts),
            'has_any_account' => $allAccounts !== [],
            'has_errors' => $errors !== [],
            'previous' => $month->previous(),
            'next' => $month->compare($current->next()) < 0 ? $month->next() : null,
            'current_month' => $current,
            'last_month' => $current->next(),
            'copy_from' => $copyFrom,
            'copied_count' => $copiedCount,
            'totals' => $totals,
            'net' => $net,
            'previous_net' => $previousNet,
            'folded_levels' => array_keys(array_filter($pending, static fn(bool $open): bool => !$open)),
            'advance_count' => count($later),
        ]);

        return $response->setStatusCode($status);
    }

    /** Value of the field: "1234,56"; "−1234,56" for a loan (the sign stays attached to the amount). */
    private static function input(Account $account, int $cents, string $locale): string
    {
        $input = Money::toInput($cents, $locale);

        return $account->isLiability() && $cents > 0 ? "\u{2212}" . $input : $input;
    }

    /** Signed contribution of an amount shown to the net worth, or null if it is empty or unreadable. */
    private static function retained(Account $account, string $input, int $share, string $locale): ?int
    {
        if (trim($input) === '') {
            return null;
        }
        try {
            $cents = Money::parse($input, $locale);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $account->signed(OwnershipShares::apply($account->isLiability() ? abs($cents) : $cents, $share));
    }
}
