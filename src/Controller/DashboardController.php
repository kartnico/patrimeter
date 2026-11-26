<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Budget\HouseholdBudget;
use Patrimeter\Application\Calendar;
use Patrimeter\Application\Family\FamilyPortfolio;
use Patrimeter\Application\Game\Game;
use Patrimeter\Application\Indicators\CustomIndicators;
use Patrimeter\Application\PropertyPortfolio;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\PyramidAdvice;
use Patrimeter\Domain\RecommendedAllocation;
use Patrimeter\Domain\Trend;
use Patrimeter\Domain\WealthDistribution;
use Patrimeter\Domain\WorldWealthDistribution;
use Patrimeter\Domain\YearToDate;
use Patrimeter\Http\HistoryChart;
use Patrimeter\Http\PerimeterSelection;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dashboard: pyramid, net total, changes over one month and over one year, position among French households
 * (WealthDistribution) and among the world's adults (WorldWealthDistribution), split of the investments compared
 * with the allocation benchmark (RecommendedAllocation), and recommendations: emergency savings relative to the
 * spending of the month's budget, pyramid rules (PyramidAdvice). Without any account, the welcome choices; then the
 * first-steps card of the challenges until they are done.
 */
final class DashboardController extends AbstractController
{
    private const int MONTHS_IN_YEAR = 12;
    /** Day of the month from which the balances of the month not entered yet are pointed out. */
    private const int ENTRY_DUE_DAY = 20;

    public function __construct(
        private readonly Calendar $calendar,
        private readonly CurrentUser $currentUser,
        private readonly HouseholdBudget $budget,
        private readonly FamilyPortfolio $family,
        private readonly PerimeterSelection $perimeters,
        private readonly HistoryChart $history,
        private readonly Game $game,
        private readonly CustomIndicators $indicators,
    ) {}

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $current = $this->calendar->currentMonth();
        $month = MonthQuery::from($request, $current);
        // Up to next month, as the entries: balances entered in advance can be looked at.
        if ($month->compare($current->next()) > 0) {
            return $this->redirectToRoute('app_dashboard', status: Response::HTTP_SEE_OTHER);
        }

        $owner = $this->currentUser->id();
        // Family account: the family, a member or this account (perimeter kept in the session).
        $selection = $this->perimeters->resolve($request, $owner);
        $perimeter = $selection['perimeter'];
        $portfolio = $this->family->portfolio($owner, $perimeter);
        // No account yet: the page stays on the current month, the one where the first accounts are created.
        if ($portfolio->accounts === [] && !$month->equals($current)) {
            return $this->redirectToRoute('app_dashboard', status: Response::HTTP_SEE_OTHER);
        }
        $calculator = $portfolio->calculator();
        $snapshot = $calculator->snapshot($month);
        $previous = $calculator->snapshot($month->previous());
        $firstMonth = $calculator->firstMonth();
        $precaution = $perimeter->isOwn()
            ? $this->budget->precaution($owner, $month, $snapshot)
            : $this->budget->precautionOf($this->family->budget($owner, $perimeter, $month)['book'], $month, $snapshot);

        return $this->render('dashboard/index.html.twig', $this->history->view($request, $calculator, $month) + [
            'month' => $month,
            'current_month' => $current,
            'last_month' => $current->next(),
            'snapshot' => $snapshot,
            'previous' => $previous,
            // Previous month (always shown) then the same month a year earlier (if data exists).
            'comparisons' => [
                $snapshot->comparedTo($previous),
                $snapshot->comparedTo($calculator->snapshot($month->addMonths(-self::MONTHS_IN_YEAR))),
            ],
            'has_accounts' => $portfolio->accounts !== [],
            // First-steps card: own wealth, accounts created, challenges on and steps left (Game).
            'first_steps' => $perimeter->isOwn() && $portfolio->accounts !== [] ? $this->game->firstSteps($owner) : null,
            // Level of the challenges in the net worth card, with a link to them (own net worth, challenges on).
            'saver_level' => $perimeter->isOwn() ? $this->game->keptLevel($owner) : null,
            // The user's custom indicators this month, from the net worth computed above (own wealth only).
            'indicators' => $perimeter->isOwn() ? $this->indicators->valuesIn($owner, $snapshot, $previous) : [],
            // Guidance while the user has not both a property and another account (an account or a loan).
            'has_property' => array_filter($portfolio->accounts, PropertyPortfolio::isProperty(...)) !== [],
            'has_other_account' => array_filter($portfolio->accounts, static fn(Account $account): bool => !PropertyPortfolio::isProperty($account)) !== [],
            // From the 20th, the balances of the current month are still not entered: a reminder in the page.
            'entry_due' => $perimeter->isOwn() && $month->equals($current) && $snapshot->enteredCount === 0 && $snapshot->carriedCount > 0
                && (int) $this->calendar->now()->format('j') >= self::ENTRY_DUE_DAY,
            'perimeters' => $selection['choices'],
            'own' => $perimeter->isOwn(),
            'account_members' => $portfolio->members,
            // Month without data before the first statement: offer to go there directly.
            'first_month' => $firstMonth !== null && $month->compare($firstMonth) < 0 ? $firstMonth : null,
            'data_first_month' => $firstMonth,
            'prev_month' => $month->previous(),
            'next_month' => $month->compare($current->next()) < 0 ? $month->next() : null,
            'wealth_rank' => $snapshot->hasData() ? WealthDistribution::rank($snapshot->net()) : null,
            'wealth_quantiles' => WealthDistribution::QUANTILES,
            'wealth_source' => WealthDistribution::SOURCE,
            'wealth_source_url' => WealthDistribution::SOURCE_URL,
            'wealth_reference' => WealthDistribution::REFERENCE,
            'allocation' => $snapshot->hasData() ? RecommendedAllocation::compare($snapshot) : null,
            // Put into or taken out of each level since the start of the year (savings of the year).
            'year_to_date' => YearToDate::of($snapshot, $calculator->snapshot(...)),
            // Change of the net worth over 3, 6 and 12 months, compared with the period before.
            'trends' => $snapshot->hasData() ? Trend::changes(static function (Month $m) use ($calculator): ?int {
                $past = $calculator->snapshot($m);

                return $past->hasData() ? $past->net() : null;
            }, $month) : [],
            'precaution' => $precaution['coverage'],
            'precaution_budgeted' => $precaution['budgeted'],
            'advice' => PyramidAdvice::of($snapshot, $precaution['coverage']),
            'allocation_source' => RecommendedAllocation::SOURCE,
            'allocation_source_url' => RecommendedAllocation::SOURCE_URL,
            'world_rank' => $snapshot->hasData() ? WorldWealthDistribution::rank($snapshot->net()) : null,
            'world_thresholds' => WorldWealthDistribution::thresholds(),
            'world_usd_per_eur' => number_format(WorldWealthDistribution::USD_PER_EUR / 10_000, 4, $request->getLocale() === 'en' ? '.' : ',', ''),
            'world_source' => WorldWealthDistribution::SOURCE,
            'world_source_url' => WorldWealthDistribution::SOURCE_URL,
            'world_reference' => WorldWealthDistribution::REFERENCE,
        ]);
    }
}
