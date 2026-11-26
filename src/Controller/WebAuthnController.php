<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Infrastructure\Mail\AccountMailer;
use Patrimeter\Infrastructure\Persistence\WebAuthnCredentialRepository;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\AuthMethods;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\Reauthentication;
use Patrimeter\Security\User;
use Patrimeter\Security\Vault\DataKeys;
use Patrimeter\Security\Vault\Vault;
use Patrimeter\Security\WebAuthn\PasskeyAuthenticator;
use Patrimeter\Security\WebAuthn\WebAuthn;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Passkeys (FIDO2) and security keys: options of the ceremonies (JSON for assets/js/webauthn.js), adding, renaming and
 * removal from the My account page (identity confirmed less than 5 minutes ago).
 */
final class WebAuthnController extends AbstractController
{
    private const string CREDENTIAL = '[A-Za-z0-9_-]{16,1400}';
    private const int LABEL_MAX = 60;

    public function __construct(
        private readonly WebAuthn $webAuthn,
        private readonly CurrentUser $currentUser,
        private readonly Reauthentication $reauthentication,
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly AuthJournal $journal,
        private readonly AccountMailer $mailer,
    ) {}

    #[Route('/connexion/passkey/options', name: 'app_passkey_login_options', methods: ['POST'])]
    public function loginOptions(Request $request): JsonResponse
    {
        return $this->options($this->webAuthn->requestOptions($request, null));
    }

    /** Intercepted by PasskeyAuthenticator. */
    #[Route('/connexion/passkey', name: PasskeyAuthenticator::ROUTE, methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Intercepté par le pare-feu (PasskeyAuthenticator).');
    }

    #[Route('/connexion/code/cle/options', name: 'app_2fa_key_options', methods: ['POST'])]
    public function secondFactorOptions(Request $request): JsonResponse
    {
        $user = $this->currentUser->pendingSecondFactor() ?? throw $this->createAccessDeniedException();

        return $this->options($this->webAuthn->requestOptions($request, $user));
    }

    #[Route('/securite/passkeys/options', name: 'app_passkey_options', methods: ['POST'])]
    public function creationOptions(Request $request): JsonResponse
    {
        $user = $this->currentUser->user();
        if (!$this->reauthentication->isFresh($request)) {
            throw $this->createAccessDeniedException();
        }

        return $this->options($this->webAuthn->creationOptions($request, $user, self::usage($request)));
    }

    #[Route('/securite/passkeys', name: 'app_passkey_register', methods: ['POST'])]
    public function register(Request $request, DataKeys $dataKeys, Vault $vault): Response
    {
        $user = $this->currentUser->user();
        if (!$this->reauthentication->isFresh($request)) {
            return $this->identityRequired();
        }
        $id = $this->webAuthn->register($request, $user, $request->request->getString('credential'), self::usage($request), self::label($request, 'Passkey'));
        if ($id === null) {
            $this->addFlash('error', t('Cet appareil n\'a pas pu être enregistré.'));

            return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
        }
        $prf = PasskeyAuthenticator::decode($request->request->getString('prf_output'));
        if ($prf !== null) {
            $dataKeys->savePasskeyEnvelope($user->id, $vault->key($user->id), $id, $prf);
        }
        $this->journal->record($user->id, AuthJournal::PASSKEY_ADDED, $request);
        $this->notify($user, t('Un nouvel appareil de connexion a été ajouté à votre compte.'));
        $this->addFlash('success', $prf !== null
            ? t('Appareil enregistré. Il déverrouillera aussi vos données.')
            : t('Appareil enregistré. Après une connexion avec lui, votre mot de passe sera demandé pour déverrouiller vos données.'));

        return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/securite/passkeys/{credential}/renommer', name: 'app_passkey_rename', requirements: ['credential' => self::CREDENTIAL], methods: ['POST'])]
    public function rename(Request $request, string $credential): Response
    {
        $user = $this->currentUser->user();
        $this->findOr404($user, $credential);
        if (!$this->reauthentication->isFresh($request)) {
            return $this->identityRequired();
        }
        $this->credentials->rename($user->id, $credential, self::label($request, 'Passkey'));
        $this->addFlash('success', t('Appareil renommé.'));

        return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/securite/passkeys/{credential}/supprimer', name: 'app_passkey_delete', requirements: ['credential' => self::CREDENTIAL], methods: ['POST'])]
    public function delete(Request $request, string $credential, DataKeys $dataKeys, AuthMethods $methods): Response
    {
        $user = $this->currentUser->user();
        $row = $this->findOr404($user, $credential);
        if (!$this->reauthentication->isFresh($request)) {
            return $this->identityRequired();
        }
        if (!$methods->canRemoveCredential($user, $row['usage'])) {
            $this->addFlash('error', t('C\'est votre dernier moyen de connexion : ajoutez-en un autre avant de le retirer.'));

            return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
        }
        $this->credentials->delete($user->id, $credential);
        $dataKeys->deletePasskeyEnvelope($user->id, $credential);
        $this->journal->record($user->id, AuthJournal::PASSKEY_REMOVED, $request);
        $this->notify($user, t('Un appareil de connexion a été retiré de votre compte.'));
        $this->addFlash('success', t('Appareil supprimé.'));

        return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
    }

    /** @return array{credential_id: string, user_id: \Patrimeter\Domain\UserId, usage: string, label: string, record: string, created_at: string, last_used_at: ?string} */
    private function findOr404(User $user, string $id): array
    {
        return $this->credentials->find($user->id, $id) ?? throw $this->createNotFoundException('Appareil introuvable.');
    }

    /** Options of the ceremony, with the PRF input (the same for everyone: the salt is in the envelope). */
    private function options(string $json): JsonResponse
    {
        return new JsonResponse(sprintf('{"options":%s,"prf":"%s"}', $json, sodium_bin2base64(hash('sha256', 'patrimeter prf v1', true), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)), json: true);
    }

    private function identityRequired(): Response
    {
        $this->addFlash('error', t('Pour cette action, confirmez d\'abord votre identité (mot de passe).'));

        return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
    }

    private function notify(User $user, TranslatableInterface $event): void
    {
        if ($user->email !== null) {
            $this->mailer->send($user->email, t('Patrimeter : appareils de connexion'), 'security_notice', ['event' => $event]);
        }
    }

    private static function usage(Request $request): string
    {
        return $request->request->getString('usage') === WebAuthn::SECURITY_KEY ? WebAuthn::SECURITY_KEY : WebAuthn::PASSKEY;
    }

    private static function label(Request $request, string $default): string
    {
        $label = mb_substr(trim($request->request->getString('label')), 0, self::LABEL_MAX);

        return $label === '' || preg_match('/\A[^\p{Cc}]+\z/u', $label) !== 1 ? $default : $label;
    }
}
