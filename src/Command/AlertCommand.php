<?php

declare(strict_types=1);

namespace Patrimeter\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Warns the administrator (ADMIN_EMAIL) by email. Run by systemd when a service fails (OnFailure= of the
 * backup); without SMTP configured, nothing is sent.
 */
#[AsCommand(name: 'app:alert', description: 'Envoie une alerte par e-mail à l\'administrateur (ADMIN_EMAIL).')]
final class AlertCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(ADMIN_EMAIL)%')]
        private readonly string $adminEmail,
        #[Autowire('%env(MAIL_FROM)%')]
        private readonly string $from,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('message', InputArgument::REQUIRED, 'Texte de l\'alerte');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $message = trim(UserCommandSupport::argument($input, 'message'));
        if ($message === '') {
            return Command::INVALID;
        }
        $host = (string) gethostname();
        $this->mailer->send((new Email())
            ->from(Address::create($this->from))
            ->to(Address::create($this->adminEmail))
            ->subject('Patrimeter : alerte sur ' . $host)
            ->text($message . "\n\nServeur : " . $host . "\n"));
        (new SymfonyStyle($input, $output))->success('Alerte envoyée à ' . $this->adminEmail . '.');

        return Command::SUCCESS;
    }
}
