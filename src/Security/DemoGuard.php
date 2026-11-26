<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Application\Demo\DemoAccounts;
use Patrimeter\Http\Flash;
use Patrimeter\Http\InternalPath;
use Patrimeter\Security\Vault\Vault;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Restrictions of the demonstration accounts (DemoAccounts), before any controller:
 * - only the wealth, monthly entry, accounts, properties, goals, budget and export screens are reachable: never the security
 *   settings, the authentication methods, the API and its tokens, the import, the family account, Carbure or anything
 *   that sends an email; any other address leads back to the dashboard;
 * - writes are limited per account (rate and size of the request) and stop at the quota of stored rows, deleting
 *   remaining possible;
 * - at the end of its lifetime, or if the mode has been disabled, the account is deleted and its session closed;
 *   signing out deletes it too.
 */
final class DemoGuard implements EventSubscriberInterface
{
    /** Routes a demonstration account may use. */
    public const array ALLOWED_ROUTES = [
        'app_login', 'app_logout', 'app_theme', 'app_locale', 'app_demo_start', 'app_demo_tour',
        'app_dashboard', 'app_history', 'app_report',
        'app_entry', 'app_entry_save',
        'app_accounts', 'app_account_new', 'app_account_create', 'app_account_edit', 'app_account_update',
        'app_account_archive', 'app_account_unarchive', 'app_account_delete', 'app_statement_save', 'app_statement_delete',
        'app_properties', 'app_property_new', 'app_property_create', 'app_share_save', 'app_share_delete', 'app_account_link',
        'app_budget', 'app_budget_entry', 'app_budget_entry_save', 'app_budget_month_delete',
        'app_budget_household', 'app_budget_household_save',
        'app_budget_category_create', 'app_budget_category_edit', 'app_budget_category_update', 'app_budget_category_delete',
        'app_budget_line_create', 'app_budget_line_edit', 'app_budget_line_update', 'app_budget_line_delete',
        'app_budget_category_move', 'app_budget_line_move',
        'app_data', 'app_data_export',
        'app_goals', 'app_goal_entry', 'app_goal_new', 'app_goal_create', 'app_goal_edit', 'app_goal_update', 'app_goal_delete',
        'app_challenges', 'app_challenges_style', 'app_challenges_settings',
    ];

    /** Writes that store nothing more: allowed even when the quota is reached, and not counted. */
    private const array FREE_ROUTES = [
        'app_logout', 'app_theme', 'app_locale', 'app_data_export', 'app_demo_tour',
        'app_account_archive', 'app_account_delete', 'app_statement_delete', 'app_share_delete',
        'app_budget_month_delete', 'app_budget_category_delete', 'app_budget_line_delete', 'app_goal_delete',
        'app_budget_category_move', 'app_budget_line_move',
    ];

    /** Largest form a demonstration account may send (nginx accepts 64 KiB). */
    public const int MAX_BODY_BYTES = 32 * 1024;

    /** After the firewall (8) and the CSRF check (7), like AccountGateSubscriber. */
    private const int PRIORITY = 6;

    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly DemoAccounts $demo,
        private readonly Vault $vault,
        private readonly TokenStorageInterface $tokens,
        private readonly UrlGeneratorInterface $urls,
        private readonly RateLimiterFactoryInterface $demoWriteLimiter,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY],
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->getBoolean('_stateless')) {
            return;
        }
        $user = $this->currentUser->authenticated();
        if ($user === null || !$user->isDemo()) {
            return;
        }
        if (!$this->demo->isEnabled() || $this->demo->hasExpired($user) || !$this->vault->isUnlocked($user->id)) {
            $this->demo->end($user->id);
            if ($request->hasSession()) {
                $request->getSession()->invalidate();
            }
            $this->tokens->setToken(null);
            Flash::add($request, 'info', t('La démonstration est terminée : le compte et ses données ont été supprimés.'));
            $event->setResponse(new RedirectResponse($this->urls->generate('app_login'), Response::HTTP_SEE_OTHER));

            return;
        }

        $route = $request->attributes->getString('_route');
        if ($route === '' || str_starts_with($route, '_')) {
            return;
        }
        if (!in_array($route, self::ALLOWED_ROUTES, true)) {
            $this->refuse($event, t('Cette fonction n\'est pas disponible dans la démonstration.'), $this->urls->generate('app_dashboard'));

            return;
        }
        if (!$request->isMethod('POST') || in_array($route, self::FREE_ROUTES, true)) {
            return;
        }
        $refusal = $this->writeRefusal($request, $user);
        if ($refusal !== null) {
            $this->refuse($event, $refusal, InternalPath::fromUrl($request->headers->get('Referer')));
        }
    }

    /** Signing out of a demonstration account deletes it, with its data. */
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User && $user->isDemo()) {
            $this->demo->end($user->id);
        }
    }

    private function writeRefusal(Request $request, User $user): ?TranslatableInterface
    {
        // Size announced, or that of the fields received (a demonstration account sends no file).
        $size = max((int) $request->headers->get('Content-Length', '0'), strlen(http_build_query($request->request->all())));
        if ($size > self::MAX_BODY_BYTES) {
            return t('Ce formulaire est trop volumineux pour la démonstration.');
        }
        if (!$this->demoWriteLimiter->create($user->id->value)->consume()->isAccepted()) {
            return t('Trop d\'enregistrements à la suite dans la démonstration : patientez un peu.');
        }
        if ($this->demo->isFull($user->id)) {
            return t('La démonstration est limitée à %rows% lignes de données : supprimez-en pour en ajouter.', ['%rows%' => $this->demo->maxRows]);
        }

        return null;
    }

    private function refuse(RequestEvent $event, TranslatableInterface $message, string $target): void
    {
        Flash::add($event->getRequest(), 'error', $message);
        $event->setResponse(new RedirectResponse($target, Response::HTTP_SEE_OTHER));
    }
}
