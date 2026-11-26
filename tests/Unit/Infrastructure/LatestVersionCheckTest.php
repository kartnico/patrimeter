<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Infrastructure;

use Patrimeter\Infrastructure\Supervision\LatestVersionCheck;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** Daily check for the latest version: disabled by default, newer version reported once, errors only logged. */
final class LatestVersionCheckTest extends TestCase
{
    private const string URL = 'https://gitlab.example.test/api/v4/projects/56/releases/permalink/latest';

    private string $directory;

    /** @var \ArrayObject<int, Email> */
    private \ArrayObject $sent;

    /** @var list<array{string, string, array<mixed>}> */
    private array $requests = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->sent = new \ArrayObject();
        $this->directory = sys_get_temp_dir() . '/patrimeter-version-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory . '/VERSION', "2.16.0\n");
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ((array) glob($this->directory . '/*') as $file) {
            unlink((string) $file);
        }
        rmdir($this->directory);
    }

    public function testDisabledWithoutAddressAndCallsNothing(): void
    {
        $check = $this->check('{"tag_name": "v9.9.9"}', url: '');

        self::assertFalse($check->isEnabled());
        self::assertNull($check->run());
        self::assertSame([], $this->requests);
        self::assertNull($check->available());
    }

    public function testANewerVersionIsReportedOnceWithTheToken(): void
    {
        $check = $this->check('{"tag_name": "v2.17.0", "name": "Patrimeter 2.17.0"}');

        self::assertSame('2.17.0', $check->run());
        self::assertSame('2.17.0', $check->available());
        self::assertSame('GET', $this->requests[0][0]);
        self::assertSame(self::URL, $this->requests[0][1]);
        self::assertContains('PRIVATE-TOKEN: read-api-token', (array) ($this->requests[0][2]['headers'] ?? []));
        self::assertCount(1, $this->sent);
        $email = $this->sent->getArrayCopy()[0];
        self::assertSame('Patrimeter : version 2.17.0 disponible', $email->getSubject());
        self::assertStringContainsString('installée : 2.16.0', (string) $email->getTextBody());

        $this->check('{"tag_name": "v2.17.0"}')->run();
        self::assertCount(1, $this->sent, 'the same version is not reported twice');
    }

    public function testTheInstalledOrAnOlderVersionIsNothingToReport(): void
    {
        file_put_contents($this->directory . '/version-disponible', "2.15.9\n");

        self::assertNull($this->check('{"tag_name": "v2.16.0"}')->run());
        self::assertNull($this->check('{"tag_name": "v2.10.0"}')->run());
        self::assertCount(0, $this->sent);
        self::assertFileDoesNotExist($this->directory . '/version-disponible', 'a stale availability is forgotten');
    }

    public function testAnUnreachableServerOrAStrangeAnswerIsOnlyLogged(): void
    {
        self::assertNull($this->check('', status: 503)->run());
        self::assertNull($this->check('{"tag_name": "dernière"}')->run());
        self::assertNull($this->check('pas du JSON')->run());
        self::assertCount(0, $this->sent);
    }

    private function check(string $body, string $url = self::URL, int $status = 200): LatestVersionCheck
    {
        $http = new MockHttpClient(function (string $method, string $requested, array $options) use ($body, $status): MockResponse {
            $this->requests[] = [$method, $requested, $options];

            return new MockResponse($body, ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/json']]);
        });
        $mailer = new class ($this->sent) implements MailerInterface {
            /** @param \ArrayObject<int, Email> $sent */
            public function __construct(private readonly \ArrayObject $sent) {}

            #[\Override]
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($message instanceof Email) {
                    $this->sent->append($message);
                }
            }
        };

        return new LatestVersionCheck(
            $http,
            $mailer,
            new NullLogger(),
            $url,
            'read-api-token',
            'admin@example.test',
            'Patrimeter <patrimeter@example.test>',
            $this->directory,
            $this->directory . '/VERSION',
        );
    }
}
