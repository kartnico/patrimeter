<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Calendar;
use Patrimeter\Application\Goals\SavingsGoals;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Money;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/**
 * Savings goals: the Goals page of the main navigation (where each one stands this month, its chart and its last
 * months), and their entry under Monthly entry > Goals (list, creation, change, deletion). The owner always comes from
 * CurrentUser; a goal of another user is not found (404).
 */
final class GoalController extends AbstractController
{
    private const string ID = '\d{1,9}';

    public function __construct(
        private readonly SavingsGoals $goals,
        private readonly CurrentUser $currentUser,
        private readonly Calendar $calendar,
    ) {}

    #[Route('/objectifs', name: 'app_goals', methods: ['GET'])]
    public function index(): Response
    {
        $owner = $this->currentUser->id();
        $month = $this->calendar->currentMonth();
        $lines = [];
        foreach ($this->goals->progress($owner, $month) as $progress) {
            $lines[] = [
                'progress' => $progress,
                'history' => $this->goals->history($owner, $progress->goal, $month),
                'series' => $this->goals->series($owner, $progress->goal, $month),
            ];
        }

        return $this->render('goals/index.html.twig', ['lines' => $lines, 'month' => $month]);
    }

    /** Monthly entry > Goals: the goals to change, and the button to add one. */
    #[Route('/saisie/objectifs', name: 'app_goal_entry', methods: ['GET'])]
    public function entry(): Response
    {
        $owner = $this->currentUser->id();

        return $this->render('goals/entry.html.twig', [
            'goals' => $this->goals->all($owner),
            'month' => $this->calendar->currentMonth(),
            'full' => $this->goals->isFull($owner),
            'max' => SavingsGoalRepository::MAX_GOALS,
        ]);
    }

    #[Route('/saisie/objectifs/nouveau', name: 'app_goal_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->renderForm(null, ['name' => '', 'target' => '', 'target_month' => $this->calendar->currentMonth()->addMonths(12)->toString(), 'scope' => GoalScope::Level->value, 'level' => Level::Precaution->value, 'kind' => 'amount', 'spending_months' => '6', 'start_month' => $this->calendar->currentMonth()->toString(), 'accounts' => []], []);
    }

    #[Route('/saisie/objectifs/nouveau', name: 'app_goal_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $owner = $this->currentUser->id();
        $fields = self::fields($request);
        if ($this->goals->isFull($owner)) {
            $this->addFlash('error', t('Vous avez déjà %count% objectifs : supprimez-en un pour en ajouter.', ['%count%' => SavingsGoalRepository::MAX_GOALS]));

            return $this->redirectToRoute('app_goal_entry', status: Response::HTTP_SEE_OTHER);
        }
        $form = $this->goals->create($owner, $fields, $request->getLocale());
        if (!$form->isValid()) {
            return $this->renderForm(null, $fields, $form->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', t('Objectif « %name% » créé : son point de départ est la valeur de ce mois-ci.', ['%name%' => $form->name]));

        return $this->redirectToRoute('app_goal_entry', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/saisie/objectifs/{id}', name: 'app_goal_edit', requirements: ['id' => self::ID], methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        $goal = $this->findOr404($id);

        return $this->renderForm($goal, [
            'name' => $goal->name,
            'target' => Money::toInput($goal->targetCents, $request->getLocale()),
            'target_month' => $goal->targetMonth->toString(),
            'scope' => $goal->scope->value,
            'level' => $goal->level->value ?? '',
            'kind' => $goal->spendingMonths === null ? 'amount' : 'spending',
            'spending_months' => (string) ($goal->spendingMonths ?? 6),
            'start_month' => $goal->startMonth->toString(),
            'accounts' => array_map(strval(...), $goal->accountIds),
        ], []);
    }

    #[Route('/saisie/objectifs/{id}', name: 'app_goal_update', requirements: ['id' => self::ID], methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $goal = $this->findOr404($id);
        $fields = self::fields($request);
        $form = $this->goals->update($this->currentUser->id(), $goal, $fields, $request->getLocale());
        if (!$form->isValid()) {
            return $this->renderForm($goal, $fields, $form->errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', t('Objectif « %name% » enregistré.', ['%name%' => $form->name]));

        return $this->redirectToRoute('app_goal_entry', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/saisie/objectifs/{id}/supprimer', name: 'app_goal_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function delete(int $id): Response
    {
        $goal = $this->findOr404($id);
        $this->goals->delete($this->currentUser->id(), $goal->id);
        $this->addFlash('success', t('Objectif « %name% » supprimé.', ['%name%' => $goal->name]));

        return $this->redirectToRoute('app_goal_entry', status: Response::HTTP_SEE_OTHER);
    }

    /** @return array{name: string, target: string, target_month: string, scope: string, level: string, kind: string, spending_months: string, start_month: string, accounts: list<string>} */
    private static function fields(Request $request): array
    {
        return [
            'name' => $request->request->getString('name'),
            'target' => $request->request->getString('target'),
            'target_month' => $request->request->getString('target_month'),
            'scope' => $request->request->getString('scope'),
            'level' => $request->request->getString('level'),
            'kind' => $request->request->getString('kind', 'amount'),
            'spending_months' => $request->request->getString('spending_months'),
            'start_month' => $request->request->getString('start_month'),
            'accounts' => array_values(array_filter($request->request->all('accounts'), is_string(...))),
        ];
    }

    private function findOr404(int $id): SavingsGoal
    {
        return $this->goals->find($this->currentUser->id(), $id) ?? throw $this->createNotFoundException('Objectif introuvable.');
    }

    /**
     * @param array{name: string, target: string, target_month: string, scope: string, level: string, kind: string, spending_months: string, start_month: string, accounts: list<string>} $values
     * @param array<string, \Symfony\Contracts\Translation\TranslatableInterface> $errors
     */
    private function renderForm(?SavingsGoal $goal, array $values, array $errors, int $status = Response::HTTP_OK): Response
    {
        return $this->render('goals/form.html.twig', [
            'goal' => $goal,
            'values' => $values,
            'errors' => $errors,
            'scopes' => GoalScope::cases(),
            'accounts' => $this->goals->accounts($this->currentUser->id()),
            'earliest' => ($goal === null ? $this->calendar->currentMonth() : $goal->startMonth)->next(),
            'latest_start' => $this->calendar->currentMonth(),
            'month' => $this->calendar->currentMonth(),
        ], new Response(status: $status));
    }
}
