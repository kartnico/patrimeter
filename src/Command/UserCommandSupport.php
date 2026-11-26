<?php

declare(strict_types=1);

namespace Patrimeter\Command;

use Patrimeter\Domain\EmailAddress;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Lookup of a user by the address given as an argument of a command. */
final class UserCommandSupport
{
    public function __construct(private readonly UserRepository $users) {}

    /** Text value of an argument of the command (empty if it is not one). */
    public static function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArguments()[$name] ?? null;

        return is_string($value) ? $value : '';
    }

    public function find(string $typedEmail, SymfonyStyle $io): ?UserId
    {
        $email = EmailAddress::normalize($typedEmail);
        $row = $email === null ? null : $this->users->findByEmail($email);
        if ($row === null) {
            $io->error('Aucun utilisateur avec cette adresse.');

            return null;
        }

        return $row['id'];
    }
}
