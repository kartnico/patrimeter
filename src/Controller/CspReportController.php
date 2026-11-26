<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Monolog\Attribute\WithMonologChannel;
use Patrimeter\Http\CspReports;
use Patrimeter\Http\SecurityHeadersSubscriber;
use Patrimeter\Security\PasswordLoginAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CSP violation reports sent by the browsers (report-uri and Reporting API): logged in var/log/csp-*.log, without
 * session nor CSRF token. A violation reveals an injection attempt, a browser extension or an error in a template.
 * Rate limited per IP address.
 */
#[WithMonologChannel('csp')]
final class CspReportController extends AbstractController
{
    /** Beyond this, the body is not read: a legitimate report is less than one kilobyte. */
    private const int MAX_BYTES = 16384;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $cspReportLimiter,
    ) {}

    #[Route(SecurityHeadersSubscriber::CSP_REPORT_PATH, name: 'app_csp_report', methods: ['POST'], stateless: true)]
    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();
        if (strlen($body) <= self::MAX_BYTES
            && $this->cspReportLimiter->create(PasswordLoginAuthenticator::clientIp($request))->consume()->isAccepted()) {
            foreach (CspReports::parse($body) as $violation) {
                $this->logger->warning('Violation de la CSP : {directive} a bloqué {blocked} sur {document}', $violation);
            }
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
