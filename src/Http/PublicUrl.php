<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Absolute addresses sent by email, built from DEFAULT_URI and never from the request's Host header: a forged
 * header cannot divert a confirmation link to another site.
 */
final class PublicUrl
{
    public function __construct(
        private readonly RouterInterface $router,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $baseUri,
    ) {}

    /** Public address of the site, without a trailing slash. */
    public function base(): string
    {
        return rtrim($this->baseUri, '/');
    }

    /** @param array<string, string> $parameters */
    public function generate(string $route, array $parameters = []): string
    {
        $context = $this->router->getContext();
        $this->router->setContext(RequestContext::fromUri($this->baseUri));
        try {
            return $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->router->setContext($context);
        }
    }
}
