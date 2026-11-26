<?php

declare(strict_types=1);

namespace Patrimeter\Command;

use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\KeyEnvelopeRepository;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\PasswordRules;
use Patrimeter\Security\Throttle\AuthThrottle;
use Patrimeter\Security\User;
use Patrimeter\Security\UserSessions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Sets the password of an account again, without the old one: ends its sessions and lifts the attempt
 * limiting. The data key being wrapped by the old password, its data becomes unreadable: it is erased, and
 * only with --erase-data. The "forgotten password" flow keeps it if the user has their recovery key.
 */
#[AsCommand(name: 'app:user:set-password', description: 'Redéfinit le mot de passe d\'un compte (lu sur l\'entrée standard en mode non interactif).')]
final class UserSetPasswordCommand extends Command
{
    public function __construct(
        private readonly UserSessions $sessions,
        private readonly PasswordRules $passwordRules,
        private readonly UserCommandSupport $support,
        private readonly UserRepository $users,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly AuthThrottle $throttle,
        private readonly KeyEnvelopeRepository $envelopes,
        private readonly AccountRepository $accounts,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail du compte');
        $this->addOption('erase-data', null, InputOption::VALUE_NONE, 'Efface définitivement les données chiffrées du compte');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = UserCommandSupport::argument($input, 'email');
        $id = $this->support->find($email, $io);
        if ($id === null) {
            return Command::FAILURE;
        }
        $hasData = $this->envelopes->hasAny($id);
        if ($hasData && $input->getOption('erase-data') !== true) {
            $io->error('Ce compte a des données chiffrées : sans son ancien mot de passe, elles seront perdues. Relancez avec --erase-data pour les effacer, ou utilisez « mot de passe oublié » avec la clé de récupération.');

            return Command::FAILURE;
        }
        $password = PasswordInput::read($input, $io, $email, $this->passwordRules);
        if ($password === null) {
            return Command::FAILURE;
        }
        $this->accounts->deleteAll($id);
        $this->envelopes->deleteAll($id);
        $this->users->updatePasswordHash($id, $this->hashers->getPasswordHasher(User::class)->hash($password));
        $this->users->renewSessionStamp($id);
        $this->sessions->revokeOthers(null, $id);
        $this->throttle->forgetAccount($email, $id);
        $io->success('Mot de passe enregistré ; les sessions ouvertes de ce compte sont terminées.');

        return Command::SUCCESS;
    }
}
