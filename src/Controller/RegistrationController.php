<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Registration;
use Patrimeter\Domain\EmailAddress;
use Patrimeter\Domain\PasswordPolicy;
use Patrimeter\Http\Sentences;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\PasswordLoginAuthenticator;
use Patrimeter\Security\PasswordRules;
use Patrimeter\Security\RetryDelay;
use Patrimeter\Security\Throttle\AuthThrottle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Registration: form, sending of the confirmation link, then confirmation of the address with the chosen password,
 * which signs the user in and takes them to setting up their TOTP.
 */
final class RegistrationController extends AbstractController
{
    private const string TOKEN = '[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}';
    private const string SENT_TO = 'registration.sent_to';

    public function __construct(
        private readonly PasswordRules $passwordRules,
        private readonly Registration $registration,
        private readonly AuthThrottle $throttle,
    ) {}

    #[Route('/inscription', name: 'app_register', methods: ['GET'])]
    public function form(): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard', status: Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('', []);
    }

    #[Route('/inscription', name: 'app_register_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        if (!$this->registration->isOpen()) {
            return $this->renderForm('', [], Response::HTTP_FORBIDDEN);
        }
        $typedEmail = mb_substr(trim($request->request->getString('email')), 0, EmailAddress::MAX_LENGTH);
        $email = EmailAddress::normalize($typedEmail);
        $password = $request->request->getString('password');
        $errors = [];
        if ($email === null) {
            $errors['email'] = t('Adresse e-mail invalide.');
        } elseif (!$this->registration->acceptsDomain($email)) {
            $errors['email'] = t('Les inscriptions sont réservées aux adresses %domains%.', ['%domains%' => implode(', ', array_map(static fn(string $d): string => "@$d", $this->registration->allowedDomains()))]);
        }
        $passwordError = $this->passwordRules->violation($password, $email);
        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        } elseif (!hash_equals($password, $request->request->getString('password_confirmation'))) {
            $errors['password_confirmation'] = t('Les deux mots de passe ne correspondent pas.');
        }
        if ($errors !== [] || $email === null) {
            return $this->renderForm($typedEmail, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ip = PasswordLoginAuthenticator::clientIp($request);
        $wait = $this->throttle->registrationRetryAfter($ip);
        if ($wait > 0) {
            $response = $this->renderForm($typedEmail, ['email' => Sentences::of(t('Trop d\'inscriptions depuis cette adresse IP.'), RetryDelay::sentence($wait))], Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) $wait);

            return $response;
        }
        $this->throttle->recordRegistration($ip);
        $this->registration->register($email, $password);
        // Address entered, recalled on the next page: a typo shows there. It reveals nothing, the answer being the
        // same whether the address already has an account or not.
        $request->getSession()->set(self::SENT_TO, $email);

        return $this->redirectToRoute('app_register_sent', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/inscription/envoyee', name: 'app_register_sent', methods: ['GET'])]
    public function sent(Request $request): Response
    {
        return $this->render('registration/sent.html.twig', ['email' => self::textOrNull($request->getSession()->remove(self::SENT_TO))]);
    }

    private static function textOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    #[Route('/inscription/confirmation/{token}', name: 'app_register_confirm', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function confirmForm(#[\SensitiveParameter] string $token): Response
    {
        if (!$this->registration->isPending($token)) {
            return $this->render('registration/invalid_link.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        return $this->render('registration/confirm.html.twig', ['token' => $token, 'error' => null]);
    }

    /** Valid link + registration password: address confirmed and user signed in. */
    #[Route('/inscription/confirmation/{token}', name: 'app_register_confirm_submit', requirements: ['token' => self::TOKEN], methods: ['POST'])]
    public function confirm(Request $request, #[\SensitiveParameter] string $token, Security $security, AuthJournal $journal): Response
    {
        $ip = PasswordLoginAuthenticator::clientIp($request);
        $wait = $this->throttle->ipRetryAfter($ip);
        if ($wait > 0) {
            return $this->render('registration/confirm.html.twig', ['token' => $token, 'error' => Sentences::of(t('Trop de tentatives.'), RetryDelay::sentence($wait))], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
        }
        if (!$this->registration->isPending($token)) {
            return $this->render('registration/invalid_link.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }
        $user = $this->registration->confirm($token, $request->request->getString('password'));
        if ($user === null) {
            $this->throttle->recordIpFailure($ip);

            return $this->render('registration/confirm.html.twig', ['token' => $token, 'error' => t('Mot de passe incorrect.')], new Response(status: Response::HTTP_UNAUTHORIZED));
        }
        $journal->record($user->id, AuthJournal::EMAIL_VERIFIED, $request);
        $security->login($user, PasswordLoginAuthenticator::class, 'main');
        $this->addFlash('success', t('Adresse confirmée. Étape suivante : protégez votre compte avec une application d\'authentification.'));

        return $this->redirectToRoute('app_totp_setup', status: Response::HTTP_SEE_OTHER);
    }

    /** @param array<string, string|TranslatableInterface> $errors messages by field, translated on display */
    private function renderForm(string $email, array $errors, int $status = Response::HTTP_OK): Response
    {
        return $this->render('registration/form.html.twig', [
            'open' => $this->registration->isOpen(),
            'email' => $email,
            'errors' => $errors,
            'min_length' => PasswordPolicy::MIN_LENGTH,
            'allowed_domains' => $this->registration->allowedDomains(),
        ], new Response(status: $status));
    }
}
