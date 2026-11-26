<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * What the installation wizard checks in the configuration (.env.local, or patrimeter.env in a container), seen from
 * the request that displays it: each point is either right, or says what to change. Nothing blocks the creation of
 * the first account; the administrator decides.
 */
final class InstallationChecks
{
    public function __construct(
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
        #[Autowire('%env(SMTP_HOST)%')]
        private readonly string $smtpHost,
        #[Autowire('%env(ADMIN_EMAIL)%')]
        private readonly string $adminEmail,
    ) {}

    /** @return list<array{label: TranslatableInterface, ok: bool, detail: TranslatableInterface}> */
    public function of(Request $request): array
    {
        $here = $request->getSchemeAndHttpHost();
        $address = rtrim($this->defaultUri, '/');
        $smtp = trim($this->smtpHost);
        $alerts = trim($this->adminEmail);

        return [
            self::check(
                t('Connexion chiffrée (HTTPS)'),
                $request->isSecure(),
                t('Les mots de passe et les données circulent chiffrés.'),
                t('Cette page est servie sans HTTPS : configurez un certificat ou un proxy inverse avant d\'y saisir vos données.'),
            ),
            self::check(
                t('Adresse du site (DEFAULT_URI)'),
                $address === $here,
                t('Les liens des e-mails mènent à %address%.', ['%address%' => $address]),
                t('Les liens des e-mails mèneront à %address% et non à %here% : corrigez DEFAULT_URI.', ['%address%' => $address, '%here%' => $here]),
            ),
            self::check(
                t('Envoi des e-mails (SMTP_HOST)'),
                $smtp !== '',
                t('Réinitialisation du mot de passe, rappels et alertes partent par %host%.', ['%host%' => $smtp]),
                t('Aucun serveur d\'envoi : ni réinitialisation du mot de passe par e-mail, ni rappel mensuel, ni alerte.'),
            ),
            self::check(
                t('Alertes de l\'administrateur (ADMIN_EMAIL)'),
                $alerts !== '' && !str_ends_with($alerts, '@localhost'),
                t('Les alertes du serveur partent vers %email%.', ['%email%' => $alerts]),
                t('Indiquez votre adresse dans ADMIN_EMAIL pour recevoir les alertes du serveur (service en panne, nouvelle version).'),
            ),
        ];
    }

    /** @return array{label: TranslatableInterface, ok: bool, detail: TranslatableInterface} */
    private static function check(TranslatableInterface $label, bool $ok, TranslatableInterface $right, TranslatableInterface $fix): array
    {
        return ['label' => $label, 'ok' => $ok, 'detail' => $ok ? $right : $fix];
    }
}
