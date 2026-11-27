<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Mail;

use Patrimeter\Infrastructure\Persistence\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sending of the account emails (plain text, templates in templates/emails/).
 *
 * The message is written in the language of the emails chosen in the recipient's profile, otherwise in the request's
 * language: subject translated right away, body rendered in that language (TemplatedEmail::locale), since the
 * request's language is no longer active when sending.
 *
 * The sending happens after the answer (kernel.terminate): the response time therefore does not reveal whether an
 * email left, nor whether an address has an account. A failure is logged without the content of the message (which
 * can contain a secret link).
 */
final class AccountMailer implements EventSubscriberInterface, ResetInterface
{
    /** @var list<TemplatedEmail> */
    private array $queue = [];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAIL_FROM)%')]
        private readonly string $from,
        private readonly TranslatorInterface $translator,
        private readonly UserRepository $users,
    ) {}

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'flush'];
    }

    /**
     * @param array<string, mixed> $context variables of the template
     * @param string|null          $locale  language of the message; null: that of the recipient's profile, otherwise
     *                                      that of the request (an account about to be deleted gives it beforehand)
     */
    public function send(string $to, string|TranslatableInterface $subject, string $template, array $context = [], ?string $locale = null): void
    {
        $this->queue[] = $this->message($to, $subject, $template, $context, $locale);
    }

    /** @param array<string, mixed> $context */
    public function sendNow(string $to, TranslatableInterface $subject, string $template, array $context, string $locale): bool
    {
        return $this->deliver($this->message($to, $subject, $template, $context, $locale));
    }

    /** @param array<string, mixed> $context */
    private function message(string $to, string|TranslatableInterface $subject, string $template, array $context, ?string $locale): TemplatedEmail
    {
        $locale ??= $this->users->emailLocaleOf($to) ?? $this->translator->getLocale();

        return (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($to)
            ->subject(is_string($subject) ? $this->translator->trans($subject, [], null, $locale) : $subject->trans($this->translator, $locale))
            ->locale($locale)
            ->textTemplate("emails/$template.txt.twig")
            ->context($context);
    }

    public function flush(): void
    {
        foreach ($this->queue as $email) {
            $this->deliver($email);
        }
        $this->queue = [];
    }

    private function deliver(TemplatedEmail $email): bool
    {
        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface) {
            $this->logger->error('Échec du transport de courrier.');

            return false;
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->queue = [];
    }
}
