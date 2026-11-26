<?php

declare(strict_types=1);

namespace Patrimeter\Application\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Patrimeter\Application\NotFound;
use Patrimeter\Application\Payload\AccountPayload;
use Patrimeter\Application\Payload\StatementPayload;
use Patrimeter\Application\PortfolioService;
use Patrimeter\Application\ValidationFailed;
use Patrimeter\Security\Api\ApiScope;
use Patrimeter\Security\CurrentUser;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Tools of the MCP service (symfony/mcp-bundle), on the same service as the REST API: the owner comes
 * from the token, and amounts stay in cents. A business error becomes a tool error.
 */
final class PortfolioTools
{
    public const array WRITE_TOOLS = ['create_account', 'save_statement', 'delete_statement'];

    public function __construct(
        private readonly PortfolioService $portfolio,
        private readonly CurrentUser $currentUser,
        private readonly Security $security,
    ) {}

    /**
     * @param string|null $month Month in the YYYY-MM format; current month by default
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_net_worth', title: 'Patrimoine net', description: 'Patrimoine net d\'un mois, au total et par niveau de la pyramide. Montants en centimes d\'euro.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function netWorth(?string $month = null): array
    {
        return $this->guarded(fn(): array => $this->portfolio->netWorth($this->currentUser->id(), $month));
    }

    /**
     * @param string|null $from First month (YYYY-MM); first statement by default
     * @param string|null $to   Last month (YYYY-MM); current month by default
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_history', title: 'Historique', description: 'Patrimoine net mois par mois, sur une période. Montants en centimes d\'euro.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function history(?string $from = null, ?string $to = null): array
    {
        return $this->guarded(fn(): array => ['months' => $this->portfolio->history($this->currentUser->id(), $from, $to)]);
    }

    /**
     * @param string|null $month Month wanted (YYYY-MM); current month by default
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_goals', title: 'Objectifs d\'épargne', description: 'Objectifs d\'épargne et où en est chacun pour un mois : valeur atteinte, effort nécessaire par mois, rythme des six derniers mois, statut (reached, ahead, on_track, watch, late, too_early), mois projeté. Montants en centimes d\'euro.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function goals(?string $month = null): array
    {
        return $this->guarded(fn(): array => ['goals' => $this->portfolio->goals($this->currentUser->id(), $month)]);
    }

    /** @return array<string, mixed> */
    #[McpTool(name: 'get_challenges', title: 'Défis et étoiles', description: 'Défis et étoiles du mois : total des étoiles et niveau, stade du profil d\'épargnant (discovery, organized, protected, serene, builder), style déclaré (ant, project, home, horizon), série de saisies, et pour chaque défi ses meilleures étoiles, la valeur mesurée et les paliers. Des habitudes, jamais un rendement : repères indicatifs, pas un conseil en investissement.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function challenges(): array
    {
        return $this->guarded(fn(): array => $this->portfolio->challenges($this->currentUser->id()));
    }

    /**
     * @param string|null $month Month wanted (YYYY-MM); current month by default
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_budget', title: 'Budget du foyer', description: 'Budget du foyer en vigueur pour un mois : adultes, totaux (revenus, besoins, envies, épargne, reste), catégories et postes avec leur montant. Montants en centimes d\'euro.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function budget(?string $month = null): array
    {
        return $this->guarded(fn(): array => $this->portfolio->budget($this->currentUser->id(), $month));
    }

    /** @return array<string, mixed> */
    #[McpTool(name: 'list_accounts', title: 'Comptes', description: 'Tous les comptes et crédits, avec leur valeur du mois courant en centimes d\'euro.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function accounts(): array
    {
        return ['accounts' => $this->portfolio->accountList($this->currentUser->id())];
    }

    /**
     * @param int $account_id Identifier of the account
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_account', title: 'Compte', description: 'Détail d\'un compte : relevés mensuels et parts de détention.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function account(int $account_id): array
    {
        return $this->guarded(fn(): array => $this->portfolio->accountDetail($this->currentUser->id(), $account_id));
    }

    /**
     * @param string|null $month Month in the YYYY-MM format; current month by default
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_properties', title: 'Biens immobiliers', description: 'Biens immobiliers d\'un mois : valeur, part, crédits et valeur nette en centimes d\'euro.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function properties(?string $month = null): array
    {
        return $this->guarded(fn(): array => ['properties' => $this->portfolio->propertyList($this->currentUser->id(), $month)]);
    }

    /** @return array<string, mixed> */
    #[McpTool(name: 'list_account_types', title: 'Types de comptes', description: 'Types de comptes disponibles, avec leur niveau et leur nature par défaut.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function accountTypes(): array
    {
        $this->currentUser->id();

        return ['account_types' => $this->portfolio->accountTypes()];
    }

    /**
     * @param string   $name                 Name of the account
     * @param int      $type_id              Identifier of the type (list_account_types)
     * @param string   $opened_on            Opening date, YYYY-MM-DD
     * @param int|null $initial_amount_cents Initial balance in cents
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'create_account', title: 'Créer un compte', description: 'Crée un compte du type indiqué, avec son niveau et sa nature par défaut.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false))]
    public function createAccount(string $name, int $type_id, string $opened_on, ?int $initial_amount_cents = null): array
    {
        return $this->writing(fn(): array => $this->portfolio->createAccount($this->currentUser->id(), new AccountPayload(
            name: $name,
            type_id: $type_id,
            opened_on: $opened_on,
            initial_amount_cents: $initial_amount_cents,
        )));
    }

    /**
     * @param int    $account_id   Identifier of the account
     * @param string $month        Month of the statement, YYYY-MM
     * @param int    $amount_cents Amount in cents; for a loan, the outstanding principal as a positive number
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'save_statement', title: 'Enregistrer un relevé', description: 'Enregistre ou remplace le relevé d\'un compte pour un mois.', annotations: new ToolAnnotations(readOnlyHint: false, idempotentHint: true))]
    public function saveStatement(int $account_id, string $month, int $amount_cents): array
    {
        return $this->writing(function () use ($account_id, $month, $amount_cents): array {
            $this->portfolio->saveStatement($this->currentUser->id(), $account_id, $month, new StatementPayload($amount_cents));

            return ['saved' => true];
        });
    }

    /**
     * @param int    $account_id Identifier of the account
     * @param string $month      Month of the statement, YYYY-MM
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'delete_statement', title: 'Supprimer un relevé', description: 'Supprime le relevé d\'un compte pour un mois : le dernier solde antérieur redevient reporté.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true))]
    public function deleteStatement(int $account_id, string $month): array
    {
        return $this->writing(function () use ($account_id, $month): array {
            $this->portfolio->deleteStatement($this->currentUser->id(), $account_id, $month);

            return ['deleted' => true];
        });
    }

    /**
     * @param callable(): array<string, mixed> $write
     *
     * @return array<string, mixed>
     */
    private function writing(callable $write): array
    {
        // ApiSubscriber already answers 403 to a plain call; this remains the real barrier, however the call is sent.
        if (!$this->security->isGranted(ApiScope::WRITE_ROLE)) {
            throw new ToolCallException('Ce jeton ne permet pas d\'écrire.');
        }

        return $this->guarded($write);
    }

    /**
     * @param callable(): array<string, mixed> $call
     *
     * @return array<string, mixed>
     */
    private function guarded(callable $call): array
    {
        try {
            return $call();
        } catch (ValidationFailed $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        } catch (NotFound) {
            throw new ToolCallException('Compte introuvable.');
        }
    }
}
