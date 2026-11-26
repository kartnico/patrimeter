<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Server of `make serve`: without a router, PHP's built-in server answered 404 for the AssetMapper assets, served on
 * the fly by Symfony and missing from the disk in development.
 */
final class DevServerRouterTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';
    private const int STARTUP_ATTEMPTS = 100;

    /** @var resource|null */
    private $server;

    #[\Override]
    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    public function testServesStaticFilesPagesAndCompiledOnTheFlyAssets(): void
    {
        $base = $this->startServer();

        [$status, $type] = self::get("$base/favicon.svg");
        self::assertSame(200, $status, 'fichier de public/');
        self::assertStringContainsString('image/svg+xml', $type);

        [$status, , $page] = self::get("$base/connexion");
        self::assertSame(200, $status, 'page Symfony');
        self::assertSame(1, preg_match('~href="(/assets/styles/app-[^"]+\.css)"~', $page, $stylesheet), 'feuille de style référencée');

        [$status, $type] = self::get($base . $stylesheet[1]);
        self::assertSame(200, $status, 'asset servi par Symfony');
        self::assertStringContainsString('text/css', $type);
    }

    private function startServer(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);

        $ini = php_ini_loaded_file();
        // As ci/start-app.sh: Surý's php.ini (variables_order=GPCS) would hide APP_ENV from the server, which would then
        // run in development mode, on a database the tests do not create.
        $command = [PHP_BINARY, ...(is_string($ini) ? ['-c', $ini] : []), '-d', 'variables_order=EGPCS', '-S', $address, '-t', 'public', 'tools/dev-server-router.php'];
        $null = ['file', '/dev/null', 'a'];
        $pipes = [];
        $server = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes, self::ROOT, ['APP_ENV' => 'test', 'APP_DEBUG' => '1']);
        self::assertIsResource($server);
        $this->server = $server;

        for ($attempt = 0; $attempt < self::STARTUP_ATTEMPTS; ++$attempt) {
            $connection = @stream_socket_client("tcp://$address", timeout: 0.1);
            if (is_resource($connection)) {
                fclose($connection);

                return "http://$address";
            }
            usleep(50_000);
        }
        self::fail('Le serveur de développement ne démarre pas.');
    }

    /** @return array{int, string, string} status, content type, body */
    private static function get(string $url): array
    {
        $stream = fopen($url, 'r', context: stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]));
        self::assertIsResource($stream, $url);
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        self::assertIsArray($headers);
        $status = 0;
        $type = '';
        foreach ($headers as $header) {
            self::assertIsString($header);
            if (preg_match('~\AHTTP/\S+ (\d+)~', $header, $match) === 1) {
                $status = (int) $match[1];
            } elseif (stripos($header, 'content-type:') === 0) {
                $type = $header;
            }
        }
        $body = stream_get_contents($stream);
        fclose($stream);

        return [$status, $type, $body === false ? '' : $body];
    }
}
