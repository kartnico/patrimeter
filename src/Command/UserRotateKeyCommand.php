<?php

declare(strict_types=1);

namespace Patrimeter\Command;

use Doctrine\DBAL\Connection;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\CarbureLinkRepository;
use Patrimeter\Infrastructure\Persistence\CustomIndicatorRepository;
use Patrimeter\Infrastructure\Persistence\FamilyMemberRepository;
use Patrimeter\Infrastructure\Persistence\KeyEnvelopeRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\SavingsGoalRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\UserSessions;
use Patrimeter\Security\Vault\DataKeys;
use Patrimeter\Security\Vault\Vault;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Full rotation of the data key (compromise): all the account's data is encrypted again, its sessions are
 * closed and its recovery key cancelled (they will write down a new one at their next sign-in). The account's
 * password is asked for, never passed as an argument.
 */
#[AsCommand(name: 'app:user:rotate-key', description: 'Rechiffre toutes les données d\'un compte avec une nouvelle clé (mot de passe du compte requis).')]
final class UserRotateKeyCommand extends Command
{
    public function __construct(
        private readonly UserSessions $sessions,
        private readonly UserCommandSupport $support,
        private readonly UserRepository $users,
        private readonly DataKeys $dataKeys,
        private readonly Vault $vault,
        private readonly KeyEnvelopeRepository $envelopes,
        private readonly AccountRepository $accounts,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
        private readonly BudgetRepository $budgets,
        private readonly FamilyMemberRepository $familyMembers,
        private readonly CarbureLinkRepository $carbureLinks,
        private readonly SavingsGoalRepository $goals,
        private readonly CustomIndicatorRepository $indicators,
        private readonly Connection $connection,
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
        $secret = PasswordInput::readCurrent($input, $io);
        $current = $this->dataKeys->keyFromPassword($id, $secret);
        if ($current === null) {
            $io->error('Mot de passe incorrect, ou compte sans données chiffrées.');

            return Command::FAILURE;
        }
        $this->vault->unlock($id, $current);
        $new = random_bytes(Aead::KEY_BYTES);
        $this->connection->transactional(function () use ($id, $new, $secret): void {
            $this->accounts->reencryptAll($id, $new);
            $this->statements->reencryptAll($id, $new);
            $this->shares->reencryptAll($id, $new);
            $this->budgets->reencryptAll($id, $new);
            $this->familyMembers->reencryptAll($id, $new);
            $this->carbureLinks->reencryptAll($id, $new);
            $this->goals->reencryptAll($id, $new);
            $this->indicators->reencryptAll($id, $new);
            // Only the given secret can wrap the new key: any other envelope (passkey, recovery…) would open the
            // old key, which no longer decrypts anything.
            $this->envelopes->deleteAll($id);
            $this->dataKeys->savePasswordEnvelope($id, $new, $secret);
            $this->users->renewSessionStamp($id);
        });
        $this->sessions->revokeOthers(null, $id);
        $io->success('Données rechiffrées ; sessions fermées. Une nouvelle clé de récupération sera demandée à la prochaine connexion, et les passkeys ne déverrouillent plus les données tant qu\'elles ne sont pas réenregistrées.');

        return Command::SUCCESS;
    }
}
