<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * nginx lets only the addresses of the application reach PHP (docs/nginx/patrimeter-app.conf, allowlist of the
 * first path segments): every route must be covered, with its methods; anything else gets a 404 from nginx.
 */
final class NginxAllowlistTest extends KernelTestCase
{
    private const string CONFIG = __DIR__ . '/../../docs/nginx/patrimeter-app.conf';

    /** Methods of the locations that do not restrict them (limit_except). */
    private const array ALL_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public function testEveryRouteIsAllowedByNginxWithItsMethods(): void
    {
        $locations = self::locations();
        $router = self::getContainer()->get('router');
        $missing = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($name, 'app_')) {
                continue;
            }
            $path = $route->getPath();
            $segment = $path === '/' ? '' : explode('/', $path)[1];
            $allowed = $segment === '.well-known' && str_starts_with($path, '/.well-known/oauth-')
                ? self::ALL_METHODS
                : ($locations[$segment] ?? []);
            $methods = $route->getMethods();
            foreach ($methods === [] ? ['GET'] : $methods as $method) {
                if (!in_array($method, $allowed, true)) {
                    $missing[] = "$method $path ($name)";
                }
            }
        }

        self::assertSame([], $missing, 'routes refused by docs/nginx/patrimeter-app.conf');
    }

    public function testUnknownAddressesAreRefusedByNginx(): void
    {
        $config = (string) file_get_contents(self::CONFIG);

        self::assertMatchesRegularExpression('/location \/ \{\s*return 404;\s*\}/', $config);
        self::assertStringContainsString('limit_req zone=patrimeter_forms', $config);
        self::assertStringContainsString('zone=patrimeter_forms', (string) file_get_contents(__DIR__ . '/../../docs/nginx/patrimeter-limits.conf'));
    }

    /** @return array<string, list<string>> first path segment => allowed methods */
    private static function locations(): array
    {
        $config = (string) file_get_contents(self::CONFIG);
        $locations = [];
        preg_match_all('/location (= \/|~ \^\/\(([a-z|-]+)\)\(\/\|\$\)) \{(.*?)\n\}/s', $config, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $segments = $match[1] === '= /' ? [''] : explode('|', $match[2]);
            $methods = preg_match('/limit_except ([A-Z ]+) \{/', $match[3], $limit) === 1
                ? explode(' ', trim($limit[1]))
                : self::ALL_METHODS;
            foreach ($segments as $segment) {
                $locations[$segment] = $methods;
            }
        }

        return $locations;
    }
}
