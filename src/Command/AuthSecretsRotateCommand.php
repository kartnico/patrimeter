<?php

declare(strict_types=1);

namespace Patrimeter\Command;

use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\AuthSecrets;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rotation of AUTH_SECRET_KEY (docs/installation.md, § 12.5): once the new key is in AUTH_SECRET_KEY and the
 * old one in AUTH_SECRET_KEY_PREVIOUS, encrypts all the TOTP secrets again with the new one. The backup codes
 * (HMAC hashes) cannot be recomputed: they stay valid as long as the old key is set. Safe to run again.
 */
#[AsCommand(name: 'app:auth-secrets:rotate', description: 'Rechiffre les secrets TOTP avec la nouvelle AUTH_SECRET_KEY (rotation).')]
final class AuthSecretsRotateCommand extends Command
{
    public function __construct(
        private readonly AuthSecrets $secrets,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->secrets->hasPreviousKey()) {
            $io->error('AUTH_SECRET_KEY_PREVIOUS est vide : renseignez l\'ancienne clé avant la rotation (docs/installation.md, § 12.5).');

            return Command::FAILURE;
        }
        $rotated = 0;
        $failed = [];
        foreach ($this->users->totpSecrets() as $id => $stored) {
            $user = UserId::fromString($id);
            try {
                $this->users->replaceTotpSecret($user, $this->secrets->reencryptTotpSecret($user, $stored));
                ++$rotated;
            } catch (\UnexpectedValueException) {
                $failed[] = $id;
            }
        }
        $io->success("$rotated secret(s) TOTP rechiffré(s) avec la nouvelle clé.");
        if ($failed !== []) {
            $io->warning(sprintf('%d secret(s) illisible(s) avec l\'une ou l\'autre clé ; ces comptes devront être réinitialisés (app:user:reset-2fa) : %s', count($failed), implode(', ', $failed)));
        }
        $io->note('Les codes de secours émis avant la rotation restent valables tant que AUTH_SECRET_KEY_PREVIOUS est renseignée : faites-les régénérer (page Mon compte), puis retirez cette variable.');

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
