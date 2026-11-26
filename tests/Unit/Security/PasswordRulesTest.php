<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Security;

use Patrimeter\Security\PasswordRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Constraints\NotCompromisedPasswordValidator;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;

/** Password rules, with Symfony's real validator and a fake leak API. */
final class PasswordRulesTest extends TestCase
{
    private const string LEAKED = 'une phrase de passe divulguee';

    /** @var list<string> requests received by the fake API */
    private array $requests = [];

    /** @return iterable<string, array{string}> */
    public static function acceptedPasswords(): iterable
    {
        yield 'phrase de passe' => ['correct cheval pile agrafe'];
        yield 'aléatoire' => ['Xk9#mQ2$vL7!pR4&'];
        yield 'accents' => ['Été à la fenêtre ouverte'];
    }

    #[DataProvider('acceptedPasswords')]
    public function testAcceptsLongOrVariedPasswords(string $password): void
    {
        self::assertNull($this->rules()->violation($password, 'marie@example.fr'));
    }

    public function testRejectsShortPasswordsCountingCharactersNotBytes(): void
    {
        self::assertStringContainsString('au moins 12 caractères', (string) $this->rules()->violation('éééééééééé1'));
        self::assertStringNotContainsString('au moins', (string) $this->rules()->violation('ééééééééééé1'), '12 caractères multi-octets : la longueur est atteinte');
        self::assertSame([], $this->requests, 'trop court : rien n\'est envoyé au service des fuites');
    }

    public function testRejectsOverlongPasswords(): void
    {
        self::assertSame('Le mot de passe est trop long.', $this->rules()->violation(str_repeat('ab1-', 1025)));
    }

    public function testRejectsPasswordsContainingTheEmailAddress(): void
    {
        self::assertStringContainsString('adresse e-mail', (string) $this->rules()->violation('xx Marie@Example.fr xx', 'marie@example.fr'));
        self::assertNull($this->rules()->violation('xx Marie@Example.fr xx', 'autre@example.fr'));
    }

    /** @return iterable<string, array{string}> */
    public static function predictablePasswords(): iterable
    {
        yield 'répétition' => ['aaaaaaaaaaaaaaaa'];
        yield 'mot et chiffres' => ['motdepasse123'];
        yield 'clavier' => ['azertyuiopqs'];
        yield 'anglais' => ['password1234'];
    }

    #[DataProvider('predictablePasswords')]
    public function testRejectsPredictablePasswords(string $password): void
    {
        self::assertStringContainsString('trop prévisible', (string) $this->rules()->violation($password));
    }

    public function testRejectsALeakedPasswordSendingOnlyTheStartOfItsHash(): void
    {
        self::assertStringContainsString('fuites de données', (string) $this->rules()->violation(self::LEAKED));

        $hash = strtoupper(sha1(self::LEAKED));
        self::assertCount(1, $this->requests);
        self::assertStringEndsWith('/range/' . substr($hash, 0, 5), $this->requests[0]);
        self::assertStringNotContainsString(substr($hash, 5), $this->requests[0]);
    }

    public function testAnUnreachableLeakServiceDoesNotBlockTheChoice(): void
    {
        self::assertNull($this->rules(available: false)->violation('correct cheval pile agrafe'));
    }

    private function rules(bool $available = true): PasswordRules
    {
        $leakedSuffix = substr(strtoupper(sha1(self::LEAKED)), 5);
        $client = new MockHttpClient(function (string $method, string $url) use ($available, $leakedSuffix): MockResponse {
            $this->requests[] = $url;

            return $available
                ? new MockResponse("0018A45C4D1DEF81644B54AB7F969B88D65:1\r\n$leakedSuffix:42")
                : new MockResponse('', ['http_code' => 503]);
        });
        $validator = Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory(new class ($client) extends ConstraintValidatorFactory {
                public function __construct(MockHttpClient $client)
                {
                    parent::__construct([NotCompromisedPasswordValidator::class => new NotCompromisedPasswordValidator($client)]);
                }
            })
            ->getValidator();

        return new PasswordRules($validator);
    }
}
