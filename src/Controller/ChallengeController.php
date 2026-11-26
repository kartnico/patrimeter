<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Game\Game;
use Patrimeter\Domain\Game\SaverLevel;
use Patrimeter\Domain\Game\SaverStage;
use Patrimeter\Domain\Game\SaverStyle;
use Patrimeter\Http\InternalPath;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/**
 * Challenges and stars (Défis page): saver profile (stage and style), level, challenges in progress and completed, and
 * the game settings (style, first-steps card, game on or off). The owner always comes from CurrentUser.
 */
final class ChallengeController extends AbstractController
{
    public function __construct(
        private readonly Game $game,
        private readonly CurrentUser $currentUser,
    ) {}

    #[Route('/defis', name: 'app_challenges', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertOffered();
        $owner = $this->currentUser->id();
        if (!$this->game->isEnabled($owner)) {
            return $this->render('challenges/disabled.html.twig');
        }

        return $this->render('challenges/index.html.twig', [
            'game' => $this->game->view($owner),
            'stages' => SaverStage::cases(),
            'levels' => SaverLevel::ladder(),
            'savings_rates' => Game::SAVINGS_RATES,
        ]);
    }

    #[Route('/defis/style', name: 'app_challenges_style', methods: ['POST'])]
    public function style(Request $request): Response
    {
        $this->assertOffered();
        $value = $request->request->getString('style');
        $style = SaverStyle::tryFrom($value);
        if ($style === null && $value !== '') {
            $this->addFlash('error', t('Style inconnu.'));
        } else {
            $this->game->setStyle($this->currentUser->id(), $style);
            $this->addFlash('success', $style === null
                ? t('Style d\'épargnant retiré.')
                : t('Style d\'épargnant enregistré : %style%.', ['%style%' => t($style->label())]));
        }

        // Back to the page of the form: the profile (Défis section) or the Défis page.
        return $this->redirect(InternalPath::sanitize($request->request->getString('_return', '/defis')), Response::HTTP_SEE_OTHER);
    }

    /** Game on or off, first-steps card hidden or shown, savings rate aimed at; back to the page that sent the form. */
    #[Route('/defis/reglages', name: 'app_challenges_settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $this->assertOffered();
        $owner = $this->currentUser->id();
        switch ($request->request->getString('action')) {
            case 'enable':
                $this->game->setEnabled($owner, true);
                $this->addFlash('success', t('Défis et étoiles activés.'));
                break;
            case 'disable':
                $this->game->setEnabled($owner, false);
                $this->addFlash('success', t('Défis et étoiles désactivés : vos étoiles sont gardées.'));
                break;
            case 'hide_first_steps':
                $this->game->setFirstStepsHidden($owner, true);
                $this->addFlash('success', t('Carte « Premiers pas » masquée : elle reste dans Défis.'));
                break;
            case 'show_first_steps':
                $this->game->setFirstStepsHidden($owner, false);
                $this->addFlash('success', t('Carte « Premiers pas » affichée sur le tableau de bord.'));
                break;
            case 'savings_rate':
                $rate = $request->request->getString('taux');
                $this->game->setSavingsRateTarget($owner, ctype_digit($rate) ? (int) $rate : null);
                $target = $this->game->savingsRateTarget($owner);
                $this->addFlash('success', $target === null
                    ? t('Taux d\'épargne visé retiré.')
                    : t('Taux d\'épargne visé : %rate% %.', ['%rate%' => $target]));
                break;
            default:
                $this->addFlash('error', t('Action inconnue.'));
        }

        return $this->redirect(InternalPath::sanitize($request->request->getString('_return', '/defis')), Response::HTTP_SEE_OTHER);
    }

    private function assertOffered(): void
    {
        if (!$this->game->isOffered()) {
            throw new NotFoundHttpException();
        }
    }
}
