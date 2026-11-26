<?php

declare(strict_types=1);

namespace Patrimeter\Command;

use Patrimeter\Application\Calendar;
use Patrimeter\Domain\EmailAddress;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\PasswordRules;
use Patrimeter\Security\User;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates an account without going through registration (server without SMTP, registrations closed).
 * The address is deemed confirmed; TOTP will be set up at the first sign-in.
 */
#[AsCommand(name: 'app:user:create', description: 'Crée un compte (mot de passe lu sur l\'entrée standard en mode non interactif).')]
final class UserCreateCommand extends Command
{
    public function __construct(
        private readonly PasswordRules $passwordRules,
        private readonly UserRepository $users,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly Calendar $calendar,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail du compte');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = EmailAddress::normalize(UserCommandSupport::argument($input, 'email'));
        if ($email === null) {
            $io->error('Adresse e-mail invalide.');

            return Command::FAILURE;
        }
        if ($this->users->emailExists($email)) {
            $io->error('Un compte existe déjà avec cette adresse.');

            return Command::FAILURE;
        }
        $password = PasswordInput::read($input, $io, $email, $this->passwordRules);
        if ($password === null) {
            return Command::FAILURE;
        }
        $now = $this->calendar->timestamp();
        $hash = $this->hashers->getPasswordHasher(User::class)->hash($password);
        $this->users->create(UserId::fromString(Uuid::v7()->toRfc4122()), $email, $hash, $now, $now);
        $io->success("Compte $email créé. L'application d'authentification sera configurée à la première connexion.");

        return Command::SUCCESS;
    }
}
