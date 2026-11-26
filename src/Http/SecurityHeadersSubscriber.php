<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Security headers of every answer of the application.
 *
 * Strict CSP: no external resource, no inline script nor style. The static files served by the web server in
 * production get their headers from the nginx configuration.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /** Address where the browser reports any CSP violation (CspReportController). */
    public const string CSP_REPORT_PATH = '/csp/rapport';

    public const string CONTENT_SECURITY_POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; "
        . "font-src 'self'; connect-src 'self'; manifest-src 'self'; form-action 'self'; "
        . "frame-ancestors 'none'; base-uri 'none'; report-uri " . self::CSP_REPORT_PATH . '; report-to csp';

    public const string PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), publickey-credentials-get=(self), publickey-credentials-create=(self)';

    /** One year: the browser will then refuse any unencrypted connection to the site. */
    private const string HSTS = 'max-age=31536000';

    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        $headers = $response->headers;

        // In development, Symfony's exception page uses inline styles.
        $isDebugErrorPage = $this->debug && $response->getStatusCode() >= 400;
        if (!$isDebugErrorPage) {
            $headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
            // Reporting API (report-to); report-uri stays for the browsers that do not know it.
            $headers->set('Reporting-Endpoints', 'csp="' . $request->getSchemeAndHttpHost() . self::CSP_REPORT_PATH . '"');
        }
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', self::HSTS);
        }
        // Personal data: never cached (the versioned assets keep their cache).
        if (!str_starts_with($request->getPathInfo(), '/assets/')) {
            $headers->addCacheControlDirective('no-store');
        }
    }
}
