<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Calendar;
use Patrimeter\Application\Family\FamilyPortfolio;
use Patrimeter\Application\Game\Game;
use Patrimeter\Http\PerimeterSelection;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Report to share: the month's dashboard on a card that fills a phone screen, to capture or to share as a picture
 * (report.js draws it on a canvas: share sheet, download, social networks). Pyramid levels only, never an account
 * name; "montants=0" only keeps the percentages, to share without revealing the sums. "etoiles=1" adds the stars and
 * the level of the challenges (own net worth, challenges on): opt-in, never an amount.
 */
final class ReportController extends AbstractController
{
    private const int MONTHS_IN_YEAR = 12;

    public function __construct(
        private readonly Calendar $calendar,
        private readonly CurrentUser $currentUser,
        private readonly FamilyPortfolio $family,
        private readonly PerimeterSelection $perimeters,
        private readonly Game $game,
    ) {}

    #[Route('/rapport', name: 'app_report', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $current = $this->calendar->currentMonth();
        $month = MonthQuery::from($request, $current);
        if ($month->compare($current->next()) > 0) {
            return $this->redirectToRoute('app_report', status: Response::HTTP_SEE_OTHER);
        }
        $amounts = $request->query->getString('montants') !== '0';

        $owner = $this->currentUser->id();
        // Perimeter kept on the dashboard (family account): the family, a member or this account.
        $selection = $this->perimeters->resolve($request, $owner);
        $calculator = $this->family->portfolio($owner, $selection['perimeter'])->calculator();
        $current = null;
        foreach ($selection['choices'] as $choice) {
            $current = $choice['current'] ? $choice : $current;
        }
        $snapshot = $calculator->snapshot($month);
        // Stars and level: own net worth only, challenges on, and asked for.
        $starsOffered = $selection['perimeter']->isOwn() && $this->game->isEnabled($owner);
        $withStars = $starsOffered && $request->query->getString('etoiles') === '1';

        return $this->render('report/index.html.twig', [
            'month' => $month,
            'snapshot' => $snapshot,
            'amounts' => $amounts,
            'comparisons' => [
                $snapshot->comparedTo($calculator->snapshot($month->previous())),
                $snapshot->comparedTo($calculator->snapshot($month->addMonths(-self::MONTHS_IN_YEAR))),
            ],
            'today' => $this->calendar->today(),
            'perimeter' => $current,
            'stars_offered' => $starsOffered,
            'game' => $withStars ? $this->game->view($owner) : null,
        ]);
    }
}
