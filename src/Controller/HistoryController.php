<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Http\HistoryChart;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Former history page (until 2.10.0): the history is now at the bottom of the wealth page. */
final class HistoryController extends AbstractController
{
    #[Route('/historique', name: 'app_history', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $query = [];
        if ($request->query->has('periode')) {
            $query['periode'] = HistoryChart::period($request);
        }
        if ($request->query->has('vue')) {
            $query['vue'] = HistoryChart::mode($request);
        }

        return $this->redirectToRoute('app_dashboard', $query + ['_fragment' => 'historique'], Response::HTTP_MOVED_PERMANENTLY);
    }
}
