<?php

declare(strict_types=1);

namespace Patrimeter\Command;

use Patrimeter\Application\Calendar;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\AuthJournal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The users of the instance, for the administrator on the server: address, creation, confirmation, second factor,
 * number of accounts and last sign-in; then the totals. Nothing is decrypted: only what the administrator can already
 * read in the database (SECURITY.md, visible metadata). --json for a script.
 */
#[AsCommand(name: 'app:user:list', description: 'Liste les utilisateurs et leur nombre de comptes (sans rien déchiffrer).')]
final class UserListCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Calendar $calendar,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Sortie JSON (un objet par utilisateur, puis les totaux)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $users = $this->users->summaries([AuthJournal::LOGIN, AuthJournal::LOGIN_TRUSTED_BROWSER]);
        $totals = [
            'users' => count($users),
            'demo' => count(array_filter($users, static fn(array $user): bool => $user['demo'])),
            'unconfirmed' => count(array_filter($users, static fn(array $user): bool => !$user['demo'] && $user['email_verified_at'] === null)),
            'accounts' => array_sum(array_column($users, 'accounts')),
        ];

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode(['users' => $users, 'totals' => $totals], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        if ($users === []) {
            $io->note('Aucun utilisateur.');

            return Command::SUCCESS;
        }
        $io->table(
            ['Adresse', 'Créé le', 'Adresse confirmée', 'Second facteur', 'Comptes', 'Dernière connexion'],
            array_map(fn(array $user): array => [
                ($user['email'] ?? '—') . ($user['demo'] ? ' (démo)' : ''),
                $this->date($user['created_at']),
                $user['email_verified_at'] === null ? 'non' : $this->date($user['email_verified_at']),
                $user['totp_enabled_at'] === null ? 'non' : 'oui',
                (string) $user['accounts'],
                $user['last_sign_in'] === null ? '—' : $this->date($user['last_sign_in']),
            ], $users),
        );
        $io->writeln(sprintf(
            '%d utilisateur%s (dont %d de démonstration, %d non confirmé%s), %d compte%s au total.',
            $totals['users'],
            $totals['users'] > 1 ? 's' : '',
            $totals['demo'],
            $totals['unconfirmed'],
            $totals['unconfirmed'] > 1 ? 's' : '',
            $totals['accounts'],
            $totals['accounts'] > 1 ? 's' : '',
        ));

        return Command::SUCCESS;
    }

    /** UTC timestamp of the database, shown in the application's time zone. */
    private function date(string $timestamp): string
    {
        return (new \DateTimeImmutable($timestamp))->setTimezone($this->calendar->timezone())->format('Y-m-d H:i');
    }
}
