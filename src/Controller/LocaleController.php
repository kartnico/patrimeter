<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Http\InternalPath;
use Patrimeter\Http\LocalePreference;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Choice of the interface language (form of the header), available without being signed in. */
final class LocaleController extends AbstractController
{
    #[Route('/langue', name: 'app_locale', methods: ['POST'])]
    public function switch(Request $request): Response
    {
        $locale = LocalePreference::requested($request->request->getString('locale'), $request->getLocale());
        $response = new RedirectResponse(InternalPath::sanitize($request->request->getString('_return')), Response::HTTP_SEE_OTHER);
        $response->headers->setCookie(LocalePreference::cookie($locale, $request->isSecure()));

        return $response;
    }
}
