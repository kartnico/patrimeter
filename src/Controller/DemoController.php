<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Demo\DemoAccounts;
use Patrimeter\Http\DemoTour;
use Patrimeter\Http\Flash;
use Patrimeter\Security\PasswordLoginAuthenticator;
use Patrimeter\Security\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** "Try the demo" button of the sign-in page (DEMO_MODE): temporary account, signed in at once, and its guided tour. */
final class DemoController extends AbstractController
{
    #[Route('/demo', name: 'app_demo_start', methods: ['POST'])]
    public function start(Request $request, DemoAccounts $demo, DemoTour $tour, Security $security): Response
    {
        if (!$demo->isEnabled()) {
            throw new NotFoundHttpException();
        }
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard', status: Response::HTTP_SEE_OTHER);
        }
        $user = $demo->start($request);
        if (!$user instanceof User) {
            Flash::add($request, 'error', $user);

            return $this->redirectToRoute('app_login', status: Response::HTTP_SEE_OTHER);
        }
        // Banner of every page (base.html.twig): fictitious data, end of the account; the guided tour starts at once.
        $security->login($user, PasswordLoginAuthenticator::class, 'main');

        return $this->redirect($tour->start($request), Response::HTTP_SEE_OTHER);
    }

    /** Guided tour of a demonstration account: start, resume, next or previous step, stop. */
    #[Route('/demo/visite', name: 'app_demo_tour', methods: ['POST'])]
    public function tour(Request $request, DemoTour $tour): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isDemo()) {
            throw new NotFoundHttpException();
        }
        $target = match ($request->request->getString('action')) {
            'start' => $tour->start($request),
            'resume' => $tour->resume($request),
            'next' => $tour->move($request, 1),
            'previous' => $tour->move($request, -1),
            'stop' => $tour->stop($request),
            default => throw new BadRequestHttpException(),
        };

        return $this->redirect($target, Response::HTTP_SEE_OTHER);
    }
}
