<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Http\Sentences;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Infrastructure\Crypto\DecryptionFailed;
use Patrimeter\Infrastructure\Mail\AccountMailer;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\Reauthentication;
use Patrimeter\Security\RetryDelay;
use Patrimeter\Security\User;
use Patrimeter\Security\Vault\DataKeys;
use Patrimeter\Security\Vault\RecoveryKey;
use Patrimeter\Security\Vault\Vault;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Vault of the user: unlocking when the session key is lost (cookie cleared), and recovery key, shown only once
 * then confirmed by entering part of it again. While waiting for confirmation, it stays in the session, encrypted
 * with the data key.
 */
final class VaultController extends AbstractController
{
    private const string PENDING_KEY = '_patrimeter_recovery_pending';

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly DataKeys $dataKeys,
        private readonly Vault $vault,
    ) {}

    #[Route('/deverrouiller', name: 'app_vault_unlock', methods: ['GET'])]
    public function unlockForm(): Response
    {
        return $this->renderUnlock($this->currentUser->user(), null);
    }

    #[Route('/deverrouiller', name: 'app_vault_unlock_submit', methods: ['POST'])]
    public function unlock(Request $request, Reauthentication $reauthentication, AuthJournal $journal): Response
    {
        $user = $this->currentUser->user();
        $secret = $request->request->getString('password');
        $result = $reauthentication->confirm($request, $user, $secret);
        if (!$result['confirmed']) {
            $delay = $result['retryAfter'] > 0 ? RetryDelay::sentence($result['retryAfter']) : null;

            return $this->renderUnlock($user, Sentences::of($result['checked'] ? t('Mot de passe incorrect.') : t('Trop de tentatives.'), $delay), Response::HTTP_UNAUTHORIZED);
        }
        $opened = $this->dataKeys->openWithPassword($request, $user->id, $secret);
        if (!$opened) {
            return $this->renderUnlock($user, t('Vos données ne s\'ouvrent pas ainsi : utilisez « mot de passe oublié » avec votre clé de récupération.'), Response::HTTP_UNAUTHORIZED);
        }
        $journal->record($user->id, AuthJournal::VAULT_UNLOCKED, $request);

        return $this->redirectToRoute('app_dashboard', status: Response::HTTP_SEE_OTHER);
    }

    private function renderUnlock(User $user, string|TranslatableInterface|null $error, int $status = Response::HTTP_OK): Response
    {
        return $this->render('vault/unlock.html.twig', [
            'error' => $error,
        ], new Response(status: $status));
    }

    #[Route('/securite/cle-de-recuperation', name: 'app_recovery_key', methods: ['GET'])]
    public function recoveryKey(Request $request): Response
    {
        $user = $this->currentUser->user();
        $pending = $this->pendingKey($request, $user);
        if ($pending === null) {
            if ($this->dataKeys->hasRecovery($user->id)) {
                return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
            }
            $pending = $this->newPendingKey($request, $user);
        }

        return $this->renderRecovery($user, $pending, null);
    }

    /** Replaces the recovery key (the previous one stays valid until the confirmation). */
    #[Route('/securite/cle-de-recuperation/nouvelle', name: 'app_recovery_key_renew', methods: ['POST'])]
    public function renew(Request $request, Reauthentication $reauthentication): Response
    {
        $user = $this->currentUser->user();
        if (!$reauthentication->isFresh($request)) {
            $this->addFlash('error', t('Pour cette action, confirmez d\'abord votre identité (mot de passe).'));

            return $this->redirectToRoute('app_security_signin', status: Response::HTTP_SEE_OTHER);
        }
        $this->newPendingKey($request, $user);

        return $this->redirectToRoute('app_recovery_key', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/securite/cle-de-recuperation', name: 'app_recovery_key_confirm', methods: ['POST'])]
    public function confirm(Request $request, AuthJournal $journal, AccountMailer $mailer): Response
    {
        $user = $this->currentUser->user();
        $pending = $this->pendingKey($request, $user);
        if ($pending === null) {
            return $this->redirectToRoute('app_recovery_key', status: Response::HTTP_SEE_OTHER);
        }
        if (!RecoveryKey::endsWith($pending, $request->request->getString('confirmation'))) {
            return $this->renderRecovery($user, $pending, t('Ces caractères ne correspondent pas à la fin de la clé.'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $replacing = $this->dataKeys->hasRecovery($user->id);
        $this->dataKeys->saveRecoveryEnvelope($user->id, $this->vault->key($user->id), $pending);
        $request->getSession()->remove(self::PENDING_KEY);
        $journal->record($user->id, AuthJournal::RECOVERY_KEY_CREATED, $request);
        if ($replacing && $user->email !== null) {
            $mailer->send($user->email, t('Patrimeter : nouvelle clé de récupération'), 'security_notice', ['event' => t('Une nouvelle clé de récupération a été enregistrée ; l\'ancienne ne fonctionne plus.')]);
        }
        $this->addFlash('success', t('Clé de récupération enregistrée.'));

        return $this->redirectToRoute('app_dashboard', status: Response::HTTP_SEE_OTHER);
    }

    private function newPendingKey(Request $request, User $user): string
    {
        $key = RecoveryKey::generate();
        $request->getSession()->set(self::PENDING_KEY, Aead::seal($this->vault->key($user->id), $key, self::context($user)));

        return $key;
    }

    private function pendingKey(Request $request, User $user): ?string
    {
        $stored = $request->getSession()->get(self::PENDING_KEY);
        if (!is_string($stored)) {
            return null;
        }
        try {
            return Aead::open($this->vault->key($user->id), $stored, self::context($user));
        } catch (DecryptionFailed) {
            return null;
        }
    }

    private function renderRecovery(User $user, #[\SensitiveParameter] string $key, string|TranslatableInterface|null $error, int $status = Response::HTTP_OK): Response
    {
        return $this->render('vault/recovery_key.html.twig', [
            'recovery_key' => RecoveryKey::format($key),
            'replacing' => $this->dataKeys->hasRecovery($user->id),
            'error' => $error,
        ], new Response(status: $status));
    }

    private static function context(User $user): string
    {
        return 'pending-recovery:' . $user->id->value;
    }
}
