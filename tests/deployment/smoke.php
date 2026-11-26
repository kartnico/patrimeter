<?php

declare(strict_types=1);

/*
 * Checks from the outside a server installed following docs/installation.md: nginx and php-fpm with the files of
 * docs/, HTTPS, code without development dependencies, real permissions ("deployment" job, ci/check-deployment.sh).
 * No dependency: only the curl extension is needed.
 *
 * Usage: php tests/deployment/smoke.php <step> [arguments]
 *   web <proxied|tls>                         headers, assets, forbidden files, limits, according to the vhost
 *   first-login <email> <password>            full first sign-in of an account created on the console
 *   add-account <email> <password> <name> <amount>
 *   set-amount <email> <password> <name> <amount>
 *   expect-amount <email> <password> <name> <amount>
 *   register <email> <password>               registration, email received by Mailpit, confirmation
 *   api <email> <password> <amount>           REST API and MCP: discovery, client registration, consent, tokens
 *   mail <recipient> <text>                   an email containing this text has arrived (Mailpit)
 *
 * Variables: BASE_URL, HTTP_URL, RESOLVE (list of host:port:address), CA_FILE, MAILPIT_API, STATE_FILE.
 */

function env(string $name, string $default): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "  ok : $message\n";
}

final class Page
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $url,
    ) {}

    public function header(string $name): string
    {
        return implode(', ', $this->headers[strtolower($name)] ?? []);
    }

    public function path(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    public function find(string $pattern, string $what): string
    {
        if (preg_match($pattern, $this->body, $match) !== 1) {
            throw new RuntimeException("$what introuvable sur $this->url (statut $this->status).");
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    public function csrf(): string
    {
        return $this->find('/name="_csrf" value="([^"]+)"/', 'Jeton CSRF');
    }

    /** Text of the page without the spaces of French amounts, to look for "1234,56" in it. */
    public function compactText(): string
    {
        return str_replace(["\u{A0}", "\u{202F}", '&nbsp;', ' '], '', $this->body);
    }
}

/** Minimal browser: cookies, redirections, server name resolved to the test address. */
final class Browser
{
    private readonly CurlHandle $curl;
    /** @var list<string> */
    private array $headers = [];

    public function __construct()
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('curl indisponible.');
        }
        $this->curl = $curl;
        $caFile = env('CA_FILE', '/etc/ssl/certs/ca-certificates.crt');
        if ($caFile === '') {
            throw new RuntimeException('CA_FILE vide.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_RESOLVE => array_values(array_filter(explode(',', env('RESOLVE', '')), static fn(string $entry): bool => $entry !== '')),
            CURLOPT_PATH_AS_IS => true,
            CURLOPT_CAINFO => $caFile,
        ]);
    }

    public function get(string $url, bool $follow = true): Page
    {
        curl_setopt($this->curl, CURLOPT_HTTPGET, true);

        return $this->send($url, $follow);
    }

    /** @param array<string, string> $form */
    public function post(string $url, array $form, bool $follow = true): Page
    {
        curl_setopt_array($this->curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)]);

        return $this->send($url, $follow);
    }

    /**
     * JSON request of an API client, without a cookie jar.
     *
     * @param non-empty-string $method
     * @param list<string> $headers
     */
    public function json(string $method, string $url, ?string $body, array $headers = []): Page
    {
        $this->headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', ...$headers];
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            curl_setopt_array($this->curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
        }
        try {
            return $this->send($url, false);
        } finally {
            curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, null);
            $this->headers = [];
        }
    }

    /** @return list<string> lines of the cookie jar */
    public function cookies(): array
    {
        return curl_getinfo($this->curl, CURLINFO_COOKIELIST);
    }

    private function send(string $url, bool $follow): Page
    {
        if ($url === '') {
            throw new RuntimeException('Adresse vide.');
        }
        $headers = [];
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower($name)][] = trim($value);
                }

                return strlen($line);
            },
        ]);
        $body = curl_exec($this->curl);
        if (!is_string($body)) {
            throw new RuntimeException("$url : " . curl_error($this->curl));
        }

        return new Page(curl_getinfo($this->curl, CURLINFO_HTTP_CODE), $headers, $body, curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL));
    }
}

/** TOTP secrets of the accounts and last time step used: a code only serves once. */
final class Totp
{
    private const int STEP = 30;

    public static function remember(string $email, string $secret): void
    {
        $state = self::state();
        $state[$email] = ['secret' => $secret, 'step' => (int) floor(time() / self::STEP)];
        self::save($state);
    }

    /** Code of the time step after the one already used; waits if needed. */
    public static function next(string $email): string
    {
        $state = self::state();
        $account = $state[$email] ?? throw new RuntimeException("Secret TOTP inconnu : $email");
        while (true) {
            $step = (int) floor(time() / self::STEP);
            $remaining = self::STEP - time() % self::STEP;
            if ($step > $account['step'] && $remaining > 3) {
                break;
            }
            sleep($remaining);
        }
        $state[$email] = ['secret' => $account['secret'], 'step' => $step];
        self::save($state);

        return self::code($account['secret'], $step);
    }

    public static function code(string $secret, int $step): string
    {
        $bits = '';
        foreach (str_split(strtoupper(str_replace([' ', '='], '', $secret))) as $char) {
            $value = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char);
            if ($value === false) {
                throw new RuntimeException('Secret TOTP invalide.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $key = implode('', array_map(static fn(string $byte): string => chr((int) bindec($byte)), str_split(substr($bits, 0, strlen($bits) - strlen($bits) % 8), 8)));
        $digest = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($digest[19]) & 0xF;
        $unpacked = unpack('N', substr($digest, $offset, 4));
        if ($unpacked === false || !is_int($unpacked[1])) {
            throw new RuntimeException('Calcul TOTP impossible.');
        }

        return str_pad((string) (($unpacked[1] & 0x7FFFFFFF) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** @return array<string, array{secret: string, step: int}> */
    private static function state(): array
    {
        $content = @file_get_contents(self::file());
        if ($content === false) {
            return [];
        }
        /** @var array<string, array{secret: string, step: int}> $state */
        $state = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $state;
    }

    /** @param array<string, array{secret: string, step: int}> $state */
    private static function save(array $state): void
    {
        file_put_contents(self::file(), json_encode($state, JSON_THROW_ON_ERROR));
    }

    private static function file(): string
    {
        return env('STATE_FILE', sys_get_temp_dir() . '/patrimeter-smoke.json');
    }
}

function base(): string
{
    return env('BASE_URL', 'https://patrimeter.example.org');
}

/**
 * Secrets read from the pages (TOTP key, recovery key): the <code> of the "secret-key" paragraph, whatever its
 * attributes (the recovery key has an id since 2.26.0, for its copy button). EncryptionTest applies them to the pages.
 */
const TOTP_SECRET_PATTERN = '/class="secret-key"><code[^>]*>([^<]+)</';
const RECOVERY_KEY_PATTERN = '/class="secret-key"><code[^>]*>([^<]+)</';

/** End of a first flow: TOTP, backup codes, recovery key. */
function finishEnrollment(Browser $browser, Page $page, string $email): Page
{
    check($page->path() === '/securite/application', 'configuration du TOTP demandée');
    $secret = str_replace(' ', '', $page->find(TOTP_SECRET_PATTERN, 'Clé TOTP'));
    check(str_contains($page->body, '<svg'), 'QR code affiché');
    Totp::remember($email, $secret);
    $codes = $browser->post(base() . '/securite/application', ['_csrf' => $page->csrf(), 'code' => Totp::code($secret, (int) floor(time() / 30))]);
    check(str_contains($codes->body, 'backup-codes'), 'TOTP activé, codes de secours affichés');

    $next = $browser->get(base() . '/');
    check($next->path() === '/securite/cle-de-recuperation', 'clé de récupération affichée');
    $groups = preg_split('/[\s-]+/', trim($next->find(RECOVERY_KEY_PATTERN, 'Clé de récupération')));
    if ($groups === false) {
        throw new RuntimeException('Clé de récupération illisible.');
    }
    $home = $browser->post(base() . '/securite/cle-de-recuperation', ['_csrf' => $next->csrf(), 'confirmation' => implode(' ', array_slice($groups, -2))]);
    check($home->path() === '/' && $home->status === 200, 'accueil atteint');

    return $home;
}

function logIn(Browser $browser, string $email, string $password): Page
{
    $login = $browser->get(base() . '/connexion');

    return $browser->post(base() . '/connexion', ['_csrf' => $login->csrf(), 'email' => $email, 'password' => $password]);
}

function logInWithCode(Browser $browser, string $email, string $password): void
{
    $codePage = logIn($browser, $email, $password);
    check($codePage->path() === '/connexion/code', 'mot de passe accepté, code demandé');
    // Scheb redirects with an absolute address: it depends on the Host and the scheme passed on by the proxy.
    check(str_starts_with($codePage->url, base()), 'redirection absolue en HTTPS, sur le bon nom');
    $home = $browser->post(base() . '/connexion/code', ['_csrf' => $codePage->csrf(), 'code' => Totp::next($email)]);
    check($home->path() === '/' && $home->status === 200, 'connecté avec le code TOTP');
}

/** Identifier of an account from its label on the entry form. */
function saveAmount(Browser $browser, string $name, string $amount): void
{
    $entry = $browser->get(base() . '/saisie');
    $field = $entry->find('/<label[^>]*for="([^"]+)"(?:(?!<\/label>).)*' . preg_quote($name, '/') . '/s', 'Champ du compte');
    $input = $entry->find('/id="' . preg_quote($field, '/') . '" name="([^"]+)"/', 'Nom du champ');
    $month = $entry->find('/name="mois" value="([^"]+)"/', 'Mois de saisie');
    $saved = $browser->post(base() . '/saisie', ['_csrf' => $entry->csrf(), 'mois' => $month, $input => $amount]);
    check($saved->status === 200 && $saved->path() === '/', "montant $amount enregistré sur « $name »");
}

function expectAmount(Browser $browser, string $name, string $amount): void
{
    $page = $browser->get(base() . '/comptes');
    check(str_contains($page->body, $name), "« $name » déchiffré sur la page des comptes");
    check(str_contains($page->compactText(), str_replace(' ', '', $amount)), "« $name » vaut $amount");
}

function stepWeb(string $mode): void
{
    $browser = new Browser();

    if ($mode === 'tls') {
        echo "HTTP vers HTTPS\n";
        $redirect = $browser->get(env('HTTP_URL', 'http://patrimeter.example.org') . '/connexion?a=1', false);
        check($redirect->status === 301, 'le port 80 redirige (301)');
        check($redirect->header('location') === 'https://patrimeter.example.org/connexion?a=1', 'vers la même adresse en HTTPS');
    }

    echo "Page de connexion\n";
    $root = $browser->get(base() . '/', false);
    check(in_array($root->status, [302, 303], true) && str_ends_with($root->header('location'), '/connexion'), '/ renvoie vers /connexion');
    // New browser: the session cookie is only set at the first visit.
    $browser = new Browser();
    $login = $browser->get(base() . '/connexion');
    check($login->status === 200, '/connexion répond 200');
    check($login->header('server') === 'nginx', 'version de nginx masquée');
    check($login->header('x-powered-by') === '', 'version de PHP masquée (expose_php=off du pool)');
    check(str_contains($login->header('content-security-policy'), "default-src 'none'"), 'CSP présente');
    check(str_contains($login->header('strict-transport-security'), 'max-age='), 'HSTS présent : Symfony voit la requête en HTTPS');
    check($login->header('x-content-type-options') === 'nosniff', 'nosniff');
    check($login->header('x-frame-options') === 'DENY', 'X-Frame-Options');
    check(str_contains($login->header('cache-control'), 'no-store'), 'pages jamais en cache');
    $cookie = strtolower($login->header('set-cookie'));
    check(str_starts_with($cookie, '__host-patrimeter='), 'cookie de session __Host-patrimeter');
    foreach (['secure', 'httponly', 'samesite=strict', 'path=/'] as $attribute) {
        check(str_contains($cookie, $attribute), "cookie de session : $attribute");
    }

    echo "Fichiers statiques\n";
    $stylesheet = $login->find('/<link[^>]+href="(\/assets\/[^"]+\.css)"/', 'Feuille de style');
    $css = $browser->get(base() . $stylesheet);
    check($css->status === 200 && str_starts_with($css->header('content-type'), 'text/css'), "$stylesheet servie en text/css");
    check(str_contains($css->header('cache-control'), 'immutable') && str_contains($css->header('cache-control'), 'max-age=31536000'), 'assets en cache un an');
    check($css->header('x-content-type-options') === 'nosniff', 'nosniff sur les assets');
    check($css->header('set-cookie') === '', 'aucun cookie sur les assets');
    $script = $login->find('/<script[^>]+src="(\/assets\/[^"]+\.js)"/', 'Script');
    $js = $browser->get(base() . $script);
    check($js->status === 200 && str_contains($js->header('content-type'), 'javascript'), "$script servi en JavaScript");
    check($browser->get(base() . '/assets/inexistant.css')->status === 404, 'asset inconnu : 404');
    check($browser->get(base() . '/favicon.svg')->header('x-content-type-options') === 'nosniff', 'favicon servi');
    check($browser->get(base() . '/robots.txt')->status === 200, 'robots.txt servi');

    echo "Fichiers interdits\n";
    foreach (
        [
            '/.env', '/.env.local', '/.git/config', '/.git/HEAD', '/index.php', '/index.php/connexion', '/autre.php',
            '/assets/autre.php', '/var/patrimeter.sqlite', '/composer.json', '/composer.lock', '/config/services.yaml',
            '/src/Kernel.php', '/docs/backup.md',
        ] as $path
    ) {
        $denied = $browser->get(base() . $path, false);
        check($denied->status === 404, "$path : 404");
    }
    foreach (['/../.env.local', '/assets/../../.env.local', '/assets/..%2f..%2f.env.local'] as $path) {
        $denied = $browser->get(base() . $path, false);
        check(in_array($denied->status, [400, 404], true) && !str_contains($denied->body, 'APP_SECRET'), "$path : refusé");
    }
    $notFound = $browser->get(base() . '/page-inexistante');
    check($notFound->status === 404 && !str_contains($notFound->body, 'Symfony') && !str_contains($notFound->body, '/srv/patrimeter'), 'page d\'erreur générique, sans détail');

    echo "Métadonnées OAuth\n";
    $metadata = $browser->get(base() . '/.well-known/oauth-authorization-server');
    check($metadata->status === 200 && str_contains($metadata->body, '"code_challenge_methods_supported":["S256"]'), 'métadonnées du serveur d\'autorisation servies malgré le blocage des fichiers cachés');
    check($browser->get(base() . '/.well-known/autre', false)->status === 404, 'autre fichier de .well-known : 404');

    echo "Limites\n";
    $tooLarge = $browser->post(base() . '/connexion', ['_csrf' => 'x', 'padding' => str_repeat('a', 70_000)]);
    check($tooLarge->status === 413, 'corps de plus de 64 Ko refusé (413)');
    $options = $browser->post(base() . '/connexion/passkey/options', ['_csrf' => $browser->get(base() . '/connexion')->csrf()]);
    check($options->status === 200 && str_contains($options->body, '"rpId":"patrimeter.example.org"'), 'WebAuthn lié au domaine de DEFAULT_URI');
}

function stepFirstLogin(string $email, string $password): void
{
    $browser = new Browser();
    $home = finishEnrollment($browser, logIn($browser, $email, $password), $email);
    check(str_contains($home->body, 'Déconnexion'), 'page réservée affichée');
    foreach ($browser->cookies() as $line) {
        check(explode("\t", $line)[3] === 'TRUE', 'cookie ' . explode("\t", $line)[5] . ' : Secure');
    }
}

function stepAddAccount(string $email, string $password, string $name, string $amount): void
{
    $browser = new Browser();
    logInWithCode($browser, $email, $password);
    $form = $browser->get(base() . '/comptes/nouveau');
    $created = $browser->post(base() . '/comptes/nouveau', [
        '_csrf' => $form->csrf(),
        'name' => $name,
        'type_id' => $form->find('/<option value="(\d+)"[^>]*>\s*Livret A/', 'Type Livret A'),
        'level_code' => '',
        'nature' => '',
        'opened_on' => $form->find('/name="opened_on" value="([^"]+)"/', 'Date d\'ouverture'),
        'initial_amount' => $amount,
    ]);
    check($created->status === 200, 'compte créé');
    expectAmount($browser, $name, $amount);
}

function stepSetAmount(string $email, string $password, string $name, string $amount): void
{
    $browser = new Browser();
    logInWithCode($browser, $email, $password);
    saveAmount($browser, $name, $amount);
    expectAmount($browser, $name, $amount);
}

function stepExpectAmount(string $email, string $password, string $name, string $amount): void
{
    $browser = new Browser();
    logInWithCode($browser, $email, $password);
    expectAmount($browser, $name, $amount);
}

function stepRegister(string $email, string $password): void
{
    $browser = new Browser();
    $form = $browser->get(base() . '/inscription');
    $sent = $browser->post(base() . '/inscription', ['_csrf' => $form->csrf(), 'email' => $email, 'password' => $password, 'password_confirmation' => $password]);
    check($sent->path() === '/inscription/envoyee', 'inscription acceptée');

    $mail = null;
    $api = env('MAILPIT_API', 'http://mailpit:8025');
    $search = $api . '/api/v1/search?query=' . rawurlencode('to:"' . $email . '"') . '&limit=1';
    for ($attempt = 0; $attempt < 40 && $mail === null; ++$attempt) {
        /** @var array{messages?: list<array{ID: string}>} $found */
        $found = json_decode($browser->get($search)->body, true, 512, JSON_THROW_ON_ERROR);
        if (($found['messages'] ?? []) !== []) {
            /** @var array{Text: string} $mail */
            $mail = json_decode($browser->get($api . '/api/v1/message/' . $found['messages'][0]['ID'])->body, true, 512, JSON_THROW_ON_ERROR);
        } else {
            usleep(250_000);
        }
    }
    check($mail !== null, 'e-mail de confirmation reçu par SMTP');
    if (preg_match('#https://patrimeter\.kartzone\.info[^\s]*/inscription/confirmation/\S+#', $mail['Text'] ?? '', $link) !== 1) {
        throw new RuntimeException('Lien de confirmation absent ou hors de DEFAULT_URI.');
    }
    check(true, 'lien du message construit depuis DEFAULT_URI');

    $confirm = $browser->get($link[0]);
    $next = $browser->post($link[0], ['_csrf' => $confirm->csrf(), 'password' => $password]);
    finishEnrollment($browser, $next, $email);
}

/** @return array<mixed> */
function decoded(Page $page): array
{
    $data = json_decode($page->body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException("JSON inattendu sur $page->url.");
    }

    return $data;
}

function requiredText(mixed $value, string $what): string
{
    if (!is_string($value)) {
        throw new RuntimeException("$what absent.");
    }

    return $value;
}

/** Call of an MCP tool following the 2026-07-28 revision: no session, metadata in each request. */
function mcpCall(Browser $browser, string $token, string $method, string $name = ''): Page
{
    $version = '2026-07-28';
    $params = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => $version, 'io.modelcontextprotocol/clientCapabilities' => new stdClass()]];
    $headers = ["Authorization: Bearer $token", "MCP-Protocol-Version: $version", "Mcp-Method: $method"];
    if ($name !== '') {
        $params += ['name' => $name, 'arguments' => new stdClass()];
        $headers[] = "Mcp-Name: $name";
    }

    return $browser->json('POST', base() . '/mcp', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR), $headers);
}

/** Full flow of an MCP client, then of a script with a personal token, on real nginx and php-fpm. */
function stepApi(string $email, string $password, string $amount): void
{
    $browser = new Browser();
    $cents = str_replace([' ', ','], '', $amount);

    echo "Découverte depuis une requête sans jeton\n";
    $denied = $browser->json('POST', base() . '/mcp', '{}');
    check($denied->status === 401, 'MCP sans jeton : 401');
    if (preg_match('/resource_metadata="([^"]+)"/', $denied->header('www-authenticate'), $match) !== 1) {
        throw new RuntimeException('resource_metadata absent de WWW-Authenticate.');
    }
    $resource = decoded($browser->get($match[1]));
    check(str_ends_with(requiredText($resource['resource'] ?? null, 'resource'), '/mcp'), 'métadonnées de la ressource');
    $issuer = is_array($resource['authorization_servers'] ?? null) ? requiredText($resource['authorization_servers'][0] ?? null, 'authorization_servers') : '';
    $server = decoded($browser->get("$issuer/.well-known/oauth-authorization-server"));
    check(requiredText($server['issuer'] ?? null, 'issuer') === $issuer, 'serveur d\'autorisation trouvé');

    echo "Inscription, connexion par les moyens habituels, consentement\n";
    $redirect = 'http://127.0.0.1:9999/retour';
    $client = decoded($browser->json('POST', requiredText($server['registration_endpoint'] ?? null, 'registration_endpoint'), json_encode(['client_name' => 'Client du test de déploiement', 'redirect_uris' => [$redirect]], JSON_THROW_ON_ERROR)));
    $clientId = requiredText($client['client_id'] ?? null, 'client_id');
    check($clientId !== '', 'client inscrit');
    $verifier = str_repeat('verificateur-', 4);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $fields = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'etat', 'scope' => 'patrimeter.read', 'resource' => base() . '/mcp'];
    logInWithCode($browser, $email, $password);
    $consent = $browser->get(requiredText($server['authorization_endpoint'] ?? null, 'authorization_endpoint') . '?' . http_build_query($fields));
    check($consent->status === 200 && str_contains($consent->body, 'Client du test de déploiement'), 'page de consentement');
    $leave = $browser->post(base() . '/oauth/authorize', $fields + ['_csrf' => $consent->csrf(), 'decision' => 'allow']);
    $back = $leave->find('/class="btn btn-primary btn-block" href="([^"]+)"/', 'Retour vers le client');
    parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
    check(str_starts_with($back, $redirect) && ($query['iss'] ?? '') === $issuer, 'code renvoyé au client, avec l\'émetteur');

    echo "Échange PKCE et outils MCP\n";
    $client = new Browser();
    $tokens = decoded($client->post(requiredText($server['token_endpoint'] ?? null, 'token_endpoint'), ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => requiredText($query['code'] ?? null, 'code'), 'redirect_uri' => $redirect, 'code_verifier' => $verifier, 'resource' => base() . '/mcp']));
    $token = requiredText($tokens['access_token'] ?? null, 'access_token');
    check(str_starts_with($token, 'patrimeter_'), 'jeton reçu');
    $discover = mcpCall($client, $token, 'server/discover');
    check($discover->status === 200 && str_contains($discover->body, 'Patrimeter'), 'server/discover à travers nginx et php-fpm');
    $net = mcpCall($client, $token, 'tools/call', 'get_net_worth');
    check($net->status === 200 && str_contains($net->body, $cents), "outil get_net_worth : données déchiffrées avec le jeton ($cents centimes)");
    $write = mcpCall($client, $token, 'tools/call', 'save_statement');
    check($write->status === 403 && str_contains($write->header('www-authenticate'), 'insufficient_scope'), 'écriture refusée à un jeton de lecture');
    $rest = $client->json('GET', base() . '/api/v1/me', null, ["Authorization: Bearer $token"]);
    check($rest->status === 401, 'jeton destiné à MCP refusé par l\'API');

    echo "Jeton personnel et API REST\n";
    $confirm = $browser->get(base() . '/securite');
    $browser->post(base() . '/securite/confirmer', ['_csrf' => $confirm->csrf(), 'password' => $password, '_return' => '/securite']);
    $created = $browser->post(base() . '/securite/jetons', ['_csrf' => $browser->get(base() . '/securite')->csrf(), 'name' => 'Script de test', 'days' => '30']);
    $personal = $created->find('/class="secret-key"><code>([^<]+)</', 'Jeton personnel');
    $worth = $client->json('GET', base() . '/api/v1/net-worth', null, ["Authorization: Bearer $personal"]);
    check($worth->status === 200 && str_contains($worth->body, '"net_cents":' . $cents), 'GET /api/v1/net-worth : Authorization transmis par le proxy, nginx et php-fpm');
    check($client->json('POST', base() . '/api/v1/accounts', '{}', ["Authorization: Bearer $personal"])->status === 403, 'écriture refusée à un jeton de lecture');
    check($client->json('GET', base() . '/api/v1/accounts/999999', null, ["Authorization: Bearer $personal"])->status === 404, 'compte inconnu : 404');
}

function stepMail(string $to, string $text): void
{
    $browser = new Browser();
    $api = env('MAILPIT_API', 'http://mailpit:8025');
    $search = $api . '/api/v1/search?query=' . rawurlencode('to:"' . $to . '"') . '&limit=1';
    for ($attempt = 0; $attempt < 40; ++$attempt) {
        $found = decoded($browser->get($search));
        $messages = is_array($found['messages'] ?? null) ? $found['messages'] : [];
        $first = $messages[0] ?? null;
        if (is_array($first)) {
            $mail = decoded($browser->get($api . '/api/v1/message/' . requiredText($first['ID'] ?? null, 'ID')));
            check(str_contains(requiredText($mail['Text'] ?? null, 'Text'), $text), "e-mail reçu par $to : « $text »");

            return;
        }
        usleep(250_000);
    }
    throw new RuntimeException("Aucun e-mail pour $to.");
}

/** @return non-empty-string */
function argument(int $index): string
{
    global $argv;
    $value = $argv[$index] ?? '';
    if ($value === '') {
        throw new RuntimeException("Argument $index manquant.");
    }

    return $value;
}

try {
    $step = argument(1);
    echo "== $step\n";
    match ($step) {
        'web' => stepWeb(argument(2)),
        'first-login' => stepFirstLogin(argument(2), argument(3)),
        'add-account' => stepAddAccount(argument(2), argument(3), argument(4), argument(5)),
        'set-amount' => stepSetAmount(argument(2), argument(3), argument(4), argument(5)),
        'expect-amount' => stepExpectAmount(argument(2), argument(3), argument(4), argument(5)),
        'register' => stepRegister(argument(2), argument(3)),
        'api' => stepApi(argument(2), argument(3), argument(4)),
        'mail' => stepMail(argument(2), argument(3)),
        default => throw new RuntimeException("Étape inconnue : $step"),
    };
} catch (Throwable $failure) {
    fwrite(STDERR, 'ÉCHEC : ' . $failure->getMessage() . "\n");
    exit(1);
}
