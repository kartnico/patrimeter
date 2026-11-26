<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Attempt refused without checking: delay or lockout in progress. The message (in French) can be
 * kept in the session until the next form is shown.
 */
final class LoginThrottledException extends AuthenticationException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Trop de tentatives.');
    }

    #[\Override]
    public function getMessageKey(): string
    {
        // French text (the message of security exceptions is a string); the pages translate their own.
        return 'Trop de tentatives. ' . RetryDelay::sentence($this->retryAfter)->trans(new IdentityTranslator(), 'fr');
    }

    #[\Override]
    public function __serialize(): array
    {
        return [$this->retryAfter, parent::__serialize()];
    }

    /** @param array<mixed> $data */
    #[\Override]
    public function __unserialize(array $data): void
    {
        $this->retryAfter = isset($data[0]) && is_int($data[0]) ? $data[0] : 0;
        parent::__unserialize(isset($data[1]) && is_array($data[1]) ? $data[1] : []);
    }
}
