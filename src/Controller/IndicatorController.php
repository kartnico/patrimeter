<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Calendar;
use Patrimeter\Application\Indicators\CustomIndicators;
use Patrimeter\Domain\Indicator\CustomIndicator;
use Patrimeter\Domain\Level;
use Patrimeter\Infrastructure\Persistence\CustomIndicatorRepository;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Custom indicators: their values this month and the form of a new one (/indicateurs), then the change of one with its
 * history (/indicateurs/{id}). Only the signed-in user's indicators, levels and accounts are ever reached.
 *
 * @phpstan-import-type IndicatorFields from CustomIndicators
 */
final class IndicatorController extends AbstractController
{
    private const string ID = '\d{1,9}';

    public function __construct(
        private readonly CustomIndicators $indicators,
        private readonly CurrentUser $currentUser,
        private readonly Calendar $calendar,
    ) {}

    #[Route('/indicateurs', name: 'app_indicators', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderIndex(self::emptyFields(), []);
    }

    #[Route('/indicateurs', name: 'app_indicator_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $fields = self::fields($request);
        $errors = $this->indicators->save($this->currentUser->id(), null, $fields);
        if ($errors !== []) {
            return $this->renderIndex($fields, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', t('Indicateur « %name% » créé.', ['%name%' => trim($fields['name'])]));

        return $this->redirectToRoute('app_indicators', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/indicateurs/{id}', name: 'app_indicator_edit', requirements: ['id' => self::ID], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $indicator = $this->findOr404($id);

        return $this->renderEdit($indicator, [
            'name' => $indicator->name,
            'levels' => array_map(static fn(Level $level): string => $level->value, $indicator->levels),
            'accounts' => array_map('strval', $indicator->accountIds),
            'share' => $indicator->shareOfNetWorth,
        ], []);
    }

    #[Route('/indicateurs/{id}', name: 'app_indicator_update', requirements: ['id' => self::ID], methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $indicator = $this->findOr404($id);
        $fields = self::fields($request);
        $errors = $this->indicators->save($this->currentUser->id(), $indicator, $fields);
        if ($errors !== []) {
            return $this->renderEdit($indicator, $fields, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', t('Indicateur « %name% » enregistré.', ['%name%' => trim($fields['name'])]));

        return $this->redirectToRoute('app_indicators', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/indicateurs/{id}/supprimer', name: 'app_indicator_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function delete(int $id): Response
    {
        $indicator = $this->findOr404($id);
        $this->indicators->delete($this->currentUser->id(), $id);
        $this->addFlash('success', t('Indicateur « %name% » supprimé.', ['%name%' => $indicator->name]));

        return $this->redirectToRoute('app_indicators', status: Response::HTTP_SEE_OTHER);
    }

    /** The signed-in user's indicator; that of another user cannot be found. */
    private function findOr404(int $id): CustomIndicator
    {
        return $this->indicators->find($this->currentUser->id(), $id) ?? throw $this->createNotFoundException('Indicateur introuvable.');
    }

    /**
     * @param IndicatorFields                      $fields
     * @param array<string, TranslatableInterface> $errors
     */
    private function renderIndex(array $fields, array $errors, int $status = Response::HTTP_OK): Response
    {
        $owner = $this->currentUser->id();
        $month = $this->calendar->currentMonth();

        return $this->render('indicators/index.html.twig', [
            'month' => $month,
            'values' => $this->indicators->values($owner, $month),
            'full' => $this->indicators->isFull($owner),
            'max_indicators' => CustomIndicatorRepository::MAX_INDICATORS,
            'accounts' => $this->indicators->accounts($owner),
            'levels' => Level::ordered(),
            'fields' => $fields,
            'errors' => $errors,
        ], new Response(status: $status));
    }

    /**
     * @param IndicatorFields                      $fields
     * @param array<string, TranslatableInterface> $errors
     */
    private function renderEdit(CustomIndicator $indicator, array $fields, array $errors, int $status = Response::HTTP_OK): Response
    {
        $owner = $this->currentUser->id();

        return $this->render('indicators/edit.html.twig', [
            'indicator' => $indicator,
            'history' => $this->indicators->history($owner, $indicator, $this->calendar->currentMonth()),
            'accounts' => $this->indicators->accounts($owner),
            'levels' => Level::ordered(),
            'fields' => $fields,
            'errors' => $errors,
        ], new Response(status: $status));
    }

    /** @return IndicatorFields */
    private static function fields(Request $request): array
    {
        return [
            'name' => $request->request->getString('name'),
            'levels' => array_values(array_filter($request->request->all('levels'), is_string(...))),
            'accounts' => array_values(array_filter($request->request->all('accounts'), is_string(...))),
            'share' => $request->request->getBoolean('share'),
        ];
    }

    /** @return IndicatorFields */
    private static function emptyFields(): array
    {
        return ['name' => '', 'levels' => [], 'accounts' => [], 'share' => false];
    }
}
