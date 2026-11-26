<?php

declare(strict_types=1);

namespace Patrimeter\Command;

use Patrimeter\Infrastructure\Persistence\BackupCodeRepository;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\UserSessions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Last resort if a user has lost their phone and their backup codes: removes their TOTP and their codes
 * (after the administrator has checked their identity). They will set up a new app at their next sign-in.
 */
#[AsCommand(name: 'app:user:reset-2fa', description: 'Supprime le TOTP et les codes de secours d\'un compte (nouvelle configuration à la connexion).')]
final class UserResetTwoFactorCommand extends Command
{
    public function __construct(
        private readonly UserSessions $sessions,
        private readonly UserCommandSupport $support,
        private readonly UserRepository $users,
        private readonly BackupCodeRepository $backupCodes,
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
        $id = $this->support->find(UserCommandSupport::argument($input, 'email'), $io);
        if ($id === null) {
            return Command::FAILURE;
        }
        $question = new ConfirmationQuestion('Supprimer l\'application d\'authentification et les codes de secours de ce compte ? (o/N)', false, '/^(o|y)/i');
        if ($io->askQuestion($question) !== true) {
            $io->warning('Rien n\'a été modifié.');

            return Command::FAILURE;
        }
        $this->users->disableTotp($id);
        $this->sessions->revokeOthers(null, $id);
        $this->backupCodes->deleteAll($id);
        $io->success('TOTP et codes de secours supprimés ; sessions terminées. Nouvelle configuration exigée à la prochaine connexion.');

        return Command::SUCCESS;
    }
}
