<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Reminder\EntryReminders;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/**
 * Monthly email reminder to enter the balances: choice in My account > Profile, and unsubscribing through the link of
 * the email, without signing in (a page, then a button: an email scanner that follows links unsubscribes nobody).
 */
final class ReminderController extends AbstractController
{
    /** Base64url link of 43 characters; looser here, the fingerprint decides. */
    private const string TOKEN = '[A-Za-z0-9._-]{16,128}';

    public function __construct(private readonly EntryReminders $reminders) {}

    #[Route('/securite/rappel', name: 'app_reminder_save', methods: ['POST'])]
    public function save(Request $request, CurrentUser $currentUser): Response
    {
        $day = $request->request->getString('day');
        $enabled = $request->request->getBoolean('enabled') && ctype_digit($day) && (int) $day >= 1 && (int) $day <= 28;
        $this->reminders->choose($currentUser->id(), $enabled ? (int) $day : null, $request->getLocale());
        $this->addFlash('success', $enabled
            ? t('Rappel enregistré : un e-mail le %day% de chaque mois si les soldes du mois ne sont pas saisis.', ['%day%' => (int) $day])
            : t('Plus de rappel par e-mail.'));

        return $this->redirectToRoute('app_security', ['_fragment' => 'reminder-title'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/rappel/desabonnement/{token}', name: 'app_reminder_unsubscribe', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function unsubscribePage(string $token): Response
    {
        return $this->render('reminder/unsubscribe.html.twig', ['token' => $token, 'valid' => $this->reminders->canUnsubscribe($token), 'done' => false]);
    }

    #[Route('/rappel/desabonnement/{token}', name: 'app_reminder_unsubscribe_confirm', requirements: ['token' => self::TOKEN], methods: ['POST'])]
    public function unsubscribe(string $token): Response
    {
        $done = $this->reminders->unsubscribe($token);

        return $this->render('reminder/unsubscribe.html.twig', ['token' => $token, 'valid' => $done, 'done' => $done], new Response(status: $done ? Response::HTTP_OK : Response::HTTP_NOT_FOUND));
    }
}
