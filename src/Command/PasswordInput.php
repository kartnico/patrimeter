<?php

declare(strict_types=1);

namespace Patrimeter\Command;

use Patrimeter\Domain\PasswordPolicy;
use Patrimeter\Security\PasswordRules;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Entry of a new password on the command line. Interactive: hidden input and confirmation. Non-interactive
 * (automation): read from the standard input, never as an argument (it would show in the process list and
 * the shell history).
 */
final class PasswordInput
{
    /** Compliant password, or null after showing the error. */
    public static function read(InputInterface $input, SymfonyStyle $io, ?string $email, PasswordRules $rules): ?string
    {
        $stream = self::stream($input);
        if ($input->isInteractive() && stream_isatty($stream)) {
            $first = self::text($io->askHidden(sprintf('Nouveau mot de passe (%d caractères minimum)', PasswordPolicy::MIN_LENGTH)));
            $second = self::text($io->askHidden('Confirmation'));
            if ($first === null || $first !== $second) {
                $io->error('Les deux saisies ne correspondent pas.');

                return null;
            }
            $password = $first;
        } else {
            $line = fgets($stream);
            $password = $line === false ? '' : rtrim($line, "\r\n");
        }
        $violation = $rules->violation($password, $email);
        if ($violation !== null) {
            $io->error($violation);

            return null;
        }

        return $password;
    }

    /** Current password of an account: hidden input, or standard input in non-interactive mode. */
    public static function readCurrent(InputInterface $input, SymfonyStyle $io): string
    {
        $stream = self::stream($input);
        if ($input->isInteractive() && stream_isatty($stream)) {
            return self::text($io->askHidden('Mot de passe du compte')) ?? '';
        }
        $line = fgets($stream);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    private static function text(mixed $answer): ?string
    {
        return is_string($answer) ? $answer : null;
    }

    /** @return resource input of the command (the standard input, except in tests) */
    private static function stream(InputInterface $input)
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;

        return $stream ?? STDIN;
    }
}
