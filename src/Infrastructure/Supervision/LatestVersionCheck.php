<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Supervision;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Daily check for the latest published version, disabled unless the administrator sets UPDATE_CHECK_URL: the GitLab
 * API address of the latest Release (…/api/v4/projects/<id>/releases/permalink/latest), with UPDATE_CHECK_TOKEN (a
 * read_api token) when the project is private. No other outgoing call, nothing about the users in the request.
 *
 * A newer version is written in var/supervision/ (read by /sante) and reported once by email to ADMIN_EMAIL.
 */
final class LatestVersionCheck
{
    public const string AVAILABLE_FILE = 'version-disponible';
    private const string NOTIFIED_FILE = 'version-signalee';
    private const string TAG = '/\A[vV]?(\d{1,4}\.\d{1,4}\.\d{1,4})\z/';
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        #[Autowire(service: 'patrimeter.update_check_http_client')]
        private readonly HttpClientInterface $http,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::UPDATE_CHECK_URL)%')]
        private readonly ?string $url,
        #[Autowire('%env(default::UPDATE_CHECK_TOKEN)%')]
        #[\SensitiveParameter]
        private readonly ?string $token,
        #[Autowire('%env(default::ADMIN_EMAIL)%')]
        private readonly ?string $adminEmail,
        #[Autowire('%env(MAIL_FROM)%')]
        private readonly string $from,
        #[Autowire(param: 'app.supervision_dir')]
        private readonly string $directory,
        #[Autowire('%kernel.project_dir%/VERSION')]
        private readonly string $versionFile,
    ) {}

    public function isEnabled(): bool
    {
        return $this->url !== null && $this->url !== '';
    }

    /** Asks for the latest version; returns it if it is newer than the installed one. Errors are only logged. */
    public function run(): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        try {
            $latest = $this->fetchLatest();
        } catch (\Throwable $error) {
            $this->logger->warning('Vérification de la dernière version impossible : {reason}', ['reason' => $error->getMessage()]);

            return null;
        }
        $current = $this->installed();
        if ($latest === null || $current === null || version_compare($latest, $current, '<=')) {
            $this->forget(self::AVAILABLE_FILE);

            return null;
        }
        $this->write(self::AVAILABLE_FILE, $latest);
        if ($this->read(self::NOTIFIED_FILE) !== $latest) {
            $this->notify($current, $latest);
            $this->write(self::NOTIFIED_FILE, $latest);
        }

        return $latest;
    }

    /** Newer version found by the last check, or null. */
    public function available(): ?string
    {
        $latest = $this->read(self::AVAILABLE_FILE);
        $current = $this->installed();

        return $latest !== null && $current !== null && version_compare($latest, $current, '>') ? $latest : null;
    }

    private function fetchLatest(): ?string
    {
        $headers = ['Accept' => 'application/json'];
        if ($this->token !== null && $this->token !== '') {
            $headers['PRIVATE-TOKEN'] = $this->token;
        }
        $response = $this->http->request('GET', (string) $this->url, [
            'headers' => $headers,
            'timeout' => self::TIMEOUT_SECONDS,
            'max_redirects' => 0,
        ]);
        return self::version($response->toArray()['tag_name'] ?? null);
    }

    /** Version number of a tag ("v2.16.0" → "2.16.0"), or null if it is not one. */
    private static function version(mixed $tag): ?string
    {
        return is_string($tag) && preg_match(self::TAG, $tag, $match) === 1 ? $match[1] : null;
    }

    private function installed(): ?string
    {
        $content = is_readable($this->versionFile) ? trim((string) file_get_contents($this->versionFile)) : '';

        return preg_match(self::TAG, $content, $match) === 1 ? $match[1] : null;
    }

    private function notify(string $current, string $latest): void
    {
        if ($this->adminEmail === null || $this->adminEmail === '') {
            return;
        }
        $this->mailer->send((new Email())
            ->from(Address::create($this->from))
            ->to(Address::create($this->adminEmail))
            ->subject('Patrimeter : version ' . $latest . ' disponible')
            ->text("Une nouvelle version de Patrimeter est publiée : $latest (installée : $current).\n\n"
                . "Notes de version et procédure de mise à jour : docs/mise-a-jour.md.\n"));
    }

    private function read(string $name): ?string
    {
        $file = $this->directory . '/' . $name;
        $content = is_readable($file) ? trim((string) file_get_contents($file)) : '';

        return $content === '' ? null : $content;
    }

    private function write(string $name, string $value): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Dossier de supervision impossible à créer : {$this->directory}");
        }
        file_put_contents($this->directory . '/' . $name, $value . "\n", LOCK_EX);
    }

    private function forget(string $name): void
    {
        $file = $this->directory . '/' . $name;
        if (is_file($file)) {
            unlink($file);
        }
    }
}
