<?php

declare(strict_types=1);

/*
 * Signs in like a browser (password, TOTP) and prints the Cookie header obtained, so that ZAP explores the
 * pages behind the sign-in. Symfony's HTTP browser (BrowserKit and HttpClient): cookies kept, redirects followed.
 * Usage: php tests/dast/session-cookie.php <url> <email>
 */

use OTPHP\TOTP;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$base = rtrim($argv[1] ?? 'http://localhost:8765', '/');
$email = $argv[2] ?? '';
$browser = new HttpBrowser(HttpClient::create());

$page = static function (string $method, string $path, array $fields = []) use ($browser, $base): string {
    $browser->request($method, $base . $path, $fields);
    $response = $browser->getInternalResponse();
    if ($response->getStatusCode() >= 400) {
        throw new RuntimeException("$method $path : HTTP {$response->getStatusCode()}.");
    }

    return $response->getContent();
};
$csrf = static function (string $html): string {
    if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $match) !== 1) {
        throw new RuntimeException('Jeton CSRF introuvable.');
    }

    return $match[1];
};

$login = $page('GET', '/connexion');
$codePage = $page('POST', '/connexion', ['_csrf' => $csrf($login), 'email' => $email, 'password' => TestUsers::PASSWORD]);
$home = $page('POST', '/connexion/code', ['_csrf' => $csrf($codePage), 'code' => TOTP::createFromSecret(TestUsers::TOTP_SECRET)->now()]);
if (!str_contains($home, 'Déconnexion')) {
    throw new RuntimeException('Connexion refusée.');
}

$pairs = [];
foreach ($browser->getCookieJar()->all() as $cookie) {
    $pairs[] = $cookie->getName() . '=' . $cookie->getValue();
}
echo implode(';', $pairs);
