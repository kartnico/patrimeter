<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Http\Appearance;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/** Appearance section of My profile: accent colour and display density of this browser (Appearance). */
final class AppearanceController extends AbstractController
{
    #[Route('/securite/apparence', name: 'app_security_appearance', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $appearance = Appearance::of($request)->with($request->request->getString('accent'), $request->request->getString('density'));
        $response = $this->redirectToRoute('app_security', ['_fragment' => 'appearance-title'], Response::HTTP_SEE_OTHER);
        foreach ($appearance->cookies($request->isSecure()) as $cookie) {
            $response->headers->setCookie($cookie);
        }
        $this->addFlash('success', t('Apparence enregistrée pour ce navigateur.'));

        return $response;
    }
}
