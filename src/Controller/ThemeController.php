<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Http\InternalPath;
use Patrimeter\Http\ThemePreference;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Theme switch without JavaScript (form of the header). With JavaScript, app.js switches directly in the page and
 * this controller is not called. Available without being signed in.
 */
final class ThemeController extends AbstractController
{
    #[Route('/theme', name: 'app_theme', methods: ['POST'])]
    public function switch(Request $request): Response
    {
        $theme = ThemePreference::requested($request->request->getString('theme'), ThemePreference::current($request));
        $response = new RedirectResponse(InternalPath::sanitize($request->request->getString('_return')), Response::HTTP_SEE_OTHER);
        $response->headers->setCookie(ThemePreference::cookie($theme, $request->isSecure()));

        return $response;
    }
}
