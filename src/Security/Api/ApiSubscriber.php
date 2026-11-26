<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

use Patrimeter\Application\Mcp\PortfolioTools;
use Patrimeter\Application\NotFound;
use Patrimeter\Application\ValidationFailed;
use Patrimeter\Http\PublicUrl;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Rules specific to the API and MCP, around what the firewall provides:
 * - the MCP service refuses a request whose Origin header is neither the site's nor an allowed one (MCP_ALLOWED_ORIGINS):
 *   403, as the MCP specification asks against DNS rebinding; a client outside a browser sends no Origin and is not
 *   concerned;
 * - the MCP bundle's route is declared stateless, like the API's;
 * - rate limited per token (api_token limiter, config/packages/rate_limiter.yaml): 429 beyond;
 * - a write MCP tool called without the scope gets the 403 that the specification asks for;
 * - any API error becomes a JSON answer (RFC 9457), without technical details.
 */
final class ApiSubscriber implements EventSubscriberInterface
{
    private const string API_PREFIX = '/api/';
    private const string MCP_ROUTE_PREFIX = '_mcp_endpoint_';
    private const string MCP_PATH = '/mcp';
    /** Before the router (32) and the firewall (8): a foreign page gets nothing, not even an authentication answer. */
    private const int PRIORITY_BEFORE_ROUTER = 40;
    /** Right after the router (32): before anything that reads the session. */
    private const int PRIORITY_AFTER_ROUTER = 31;
    /** After the firewall (8). */
    private const int PRIORITY_AFTER_FIREWALL = 7;
    /** After the error is logged (0), before it is rendered as an HTML page (-128). */
    private const int PRIORITY_BEFORE_ERROR_PAGE = -64;

    public function __construct(
        private readonly Security $security,
        private readonly ApiAuthResponses $responses,
        private readonly RateLimiterFactoryInterface $apiTokenLimiter,
        private readonly PublicUrl $urls,
        #[Autowire('%env(MCP_ALLOWED_ORIGINS)%')]
        private readonly string $allowedOrigins = '',
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['refuseForeignMcpOrigin', self::PRIORITY_BEFORE_ROUTER],
                ['markMcpStateless', self::PRIORITY_AFTER_ROUTER],
                ['limitTokenRate', self::PRIORITY_AFTER_FIREWALL],
                ['enforceMcpWriteScope', self::PRIORITY_AFTER_FIREWALL],
            ],
            KernelEvents::EXCEPTION => ['renderProblem', self::PRIORITY_BEFORE_ERROR_PAGE],
        ];
    }

    public function refuseForeignMcpOrigin(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $origin = $request->headers->get('Origin');
        if (!$event->isMainRequest() || $origin === null || !str_starts_with($request->getPathInfo(), self::MCP_PATH)
            || in_array(self::normalizeOrigin($origin), $this->allowedOrigins(), true)) {
            return;
        }
        $event->setResponse(self::problem(Response::HTTP_FORBIDDEN, 'Origine non autorisée'));
    }

    /**
     * Origins allowed to call the MCP service: the site's (DEFAULT_URI), and those of MCP_ALLOWED_ORIGINS, separated by
     * commas or spaces.
     *
     * @return list<string>
     */
    private function allowedOrigins(): array
    {
        $origins = [self::normalizeOrigin($this->urls->base())];
        $listed = preg_split('/[\s,]+/', $this->allowedOrigins, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($listed === false ? [] : $listed as $origin) {
            $origins[] = self::normalizeOrigin($origin);
        }

        return $origins;
    }

    /** Scheme, host and port, in lower case, without path nor trailing slash ("null" stays "null": refused). */
    private static function normalizeOrigin(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return strtolower(rtrim(trim($url), '/'));
        }

        return strtolower($parts['scheme'] . '://' . $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public function markMcpStateless(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && str_starts_with($request->attributes->getString('_route'), self::MCP_ROUTE_PREFIX)) {
            $request->attributes->set('_stateless', true);
        }
    }

    /**
     * The firewall has already refused any invalid token: only valid tokens are counted, each one
     * separately (hash of the token, never the token itself).
     */
    public function limitTokenRate(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $authorization = (string) $request->headers->get('Authorization');
        if (!$event->isMainRequest() || (!str_starts_with($path, self::API_PREFIX) && $path !== self::MCP_PATH)
            || !str_starts_with($authorization, 'Bearer ')) {
            return;
        }
        $limit = $this->apiTokenLimiter->create(hash('sha256', substr($authorization, 7)))->consume();
        if ($limit->isAccepted()) {
            return;
        }
        $wait = max(1, (int) ceil((float) $limit->getRetryAfter()->format('U.u') - microtime(true)));
        $response = self::problem(Response::HTTP_TOO_MANY_REQUESTS, 'Trop de requêtes', ['retry_after' => $wait]);
        $response->headers->set('Retry-After', (string) $wait);
        $event->setResponse($response);
    }

    public function enforceMcpWriteScope(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->attributes->getString('_route'), self::MCP_ROUTE_PREFIX) || !$request->isMethod('POST')) {
            return;
        }
        try {
            $message = $request->toArray();
        } catch (JsonException) {
            return;
        }
        if (($message['method'] ?? null) === 'tools/call' && isset($message['params']) && is_array($message['params'])
            && in_array($message['params']['name'] ?? null, PortfolioTools::WRITE_TOOLS, true) && !$this->security->isGranted(ApiScope::WRITE_ROLE)) {
            $event->setResponse($this->responses->insufficientScope($request));
        }
    }

    public function renderProblem(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), self::API_PREFIX)) {
            return;
        }
        $error = $event->getThrowable();
        // framework.exceptions (framework.yaml) wraps the original exception in an HTTP exception.
        $cause = $error->getPrevious() ?? $error;
        $event->allowCustomResponseCode();
        $event->setResponse(match (true) {
            $cause instanceof ValidationFailed => self::problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'Données invalides', ['errors' => $cause->errors]),
            $cause instanceof ValidationFailedException => self::problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'Données invalides', ['errors' => self::errors($cause)]),
            $cause instanceof NotFound => self::problem(Response::HTTP_NOT_FOUND, 'Ressource introuvable'),
            $cause instanceof RequestExceptionInterface => self::problem(Response::HTTP_BAD_REQUEST, 'Requête invalide'),
            $error instanceof HttpExceptionInterface => self::problem($error->getStatusCode(), Response::$statusTexts[$error->getStatusCode()] ?? 'Erreur'),
            default => self::problem(Response::HTTP_INTERNAL_SERVER_ERROR, 'Erreur interne'),
        });
    }

    /**
     * Violations of #[MapRequestPayload]: those of our constraints are in French; the one for an unexpected value
     * type, raised by Symfony, is translated here.
     *
     * @return array<string, string>
     */
    private static function errors(ValidationFailedException $failure): array
    {
        $errors = [];
        foreach ($failure->getViolations() as $violation) {
            $errors[$violation->getPropertyPath()] ??= $violation->getMessageTemplate() === 'This value should be of type {{ type }}.'
                ? 'Type de valeur inattendu.'
                : (string) $violation->getMessage();
        }

        return $errors;
    }

    /** @param array<string, mixed> $extra */
    private static function problem(int $status, string $title, array $extra = []): JsonResponse
    {
        return new JsonResponse(['title' => $title, 'status' => $status] + $extra, $status, ['Content-Type' => 'application/problem+json']);
    }
}
