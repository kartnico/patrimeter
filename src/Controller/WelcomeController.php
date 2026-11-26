<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Calendar;
use Patrimeter\Application\PortfolioService;
use Patrimeter\Application\Welcome\BeginnerQuestions;
use Patrimeter\Application\Welcome\StarterAccounts;
use Patrimeter\Domain\Game\SaverStyle;
use Patrimeter\Domain\Level;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/**
 * Welcome of a new user, offered by the dashboard while there is no account: build the pyramid level by level (each
 * level's common account types and this month's balances), enter every account on a single page (express), or answer
 * simple questions, one per screen (beginners). The owner always comes from CurrentUser; the accounts are created by
 * StarterAccounts, in one transaction.
 */
final class WelcomeController extends AbstractController
{
    private const string LEVELS = 'residence|precaution|fonds_euros|actions|immobilier|exotique';

    public function __construct(
        private readonly StarterAccounts $starters,
        private readonly PortfolioService $portfolio,
        private readonly AccountRepository $accounts,
        private readonly CurrentUser $currentUser,
        private readonly Calendar $calendar,
        private readonly BeginnerQuestions $questions,
    ) {}

    #[Route('/bienvenue/pyramide', name: 'app_welcome_pyramid', methods: ['GET'])]
    public function pyramid(): Response
    {
        $owner = $this->currentUser->id();
        $month = $this->calendar->currentMonth();
        $snapshot = $this->portfolio->netWorthSnapshot($owner, $month);
        $counts = array_fill_keys(array_map(static fn(Level $level): string => $level->value, Level::cases()), 0);
        foreach ($this->accounts->all($owner) as $account) {
            ++$counts[$account->level->value];
        }
        $levels = [];
        foreach (Level::ordered() as $level) {
            $levels[] = ['level' => $level, 'count' => $counts[$level->value], 'net' => $snapshot->level($level)->net()];
        }

        return $this->render('welcome/pyramid.html.twig', [
            'snapshot' => $snapshot,
            'levels' => $levels,
            'filled' => count(array_filter($counts, static fn(int $count): bool => $count > 0)),
            'month' => $month,
        ]);
    }

    #[Route('/bienvenue/niveau/{level}', name: 'app_welcome_level', requirements: ['level' => self::LEVELS], methods: ['GET'])]
    public function level(string $level): Response
    {
        return $this->renderForm(Level::from($level), [], []);
    }

    #[Route('/bienvenue/express', name: 'app_welcome_express', methods: ['GET'])]
    public function express(): Response
    {
        return $this->renderForm(null, [], []);
    }

    /** Creates the accounts of a level's page or of the express page; the page again, with its errors, if one is wrong. */
    #[Route('/bienvenue/comptes', name: 'app_welcome_accounts', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $level = Level::tryFrom($request->request->getString('niveau'));
        $rows = $request->request->all('comptes');
        $result = $this->starters->create($this->currentUser->id(), $rows, $request->getLocale());
        if ($result['errors'] !== []) {
            return $this->renderForm($level, $rows, $result['errors'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($result['created'] > 0) {
            $this->addFlash('success', t('%count% compte créé, avec son solde de ce mois.|%count% comptes créés, avec leur solde de ce mois.', ['%count%' => $result['created']]));
        }

        return $level === null
            ? $this->redirectToRoute('app_dashboard', status: Response::HTTP_SEE_OTHER)
            : $this->redirectToRoute('app_welcome_pyramid', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/bienvenue/questions', name: 'app_welcome_questions', methods: ['GET'])]
    public function questions(): Response
    {
        return $this->renderQuestions([], [], []);
    }

    /** Creates the accounts answered; the questions again, with their errors, if an exact amount is wrong. */
    #[Route('/bienvenue/questions', name: 'app_welcome_questions_save', methods: ['POST'])]
    public function answer(Request $request): Response
    {
        $answers = $request->request->all('reponses');
        $amounts = $request->request->all('montants');
        $result = $this->questions->answer($this->currentUser->id(), $answers, $amounts, $request->getLocale());
        if ($result['errors'] !== []) {
            return $this->renderQuestions($answers, $amounts, $result['errors'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash($result['created'] > 0 ? 'success' : 'info', $result['created'] > 0
            ? t('%count% compte créé, avec son solde de ce mois.|%count% comptes créés, avec leur solde de ce mois.', ['%count%' => $result['created']])
            : t('Aucun compte créé : vous pourrez commencer autrement, depuis la page Patrimoine.'));

        return $this->redirectToRoute('app_dashboard', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<array-key, mixed> $answers answers given, by type code (and "style")
     * @param array<array-key, mixed> $amounts exact amounts typed, by type code
     * @param array<string, string>   $errors  by type code
     */
    private function renderQuestions(array $answers, array $amounts, array $errors, int $status = Response::HTTP_OK): Response
    {
        return $this->render('welcome/questions.html.twig', [
            'questions' => $this->questions->questions(),
            'styles' => SaverStyle::cases(),
            'answers' => $answers,
            'amounts' => $amounts,
            'errors' => $errors,
            'exact' => BeginnerQuestions::EXACT,
            'month' => $this->calendar->currentMonth(),
        ], new Response(status: $status));
    }

    /**
     * @param array<array-key, mixed> $values what was typed, by type code
     * @param array<string, string>   $errors by type code
     */
    private function renderForm(?Level $level, array $values, array $errors, int $status = Response::HTTP_OK): Response
    {
        return $this->render('welcome/accounts.html.twig', [
            'level' => $level,
            'groups' => $this->starters->byLevel($level),
            'values' => $values,
            'errors' => $errors,
            'month' => $this->calendar->currentMonth(),
        ], new Response(status: $status));
    }
}
