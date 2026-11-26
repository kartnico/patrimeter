<?php

declare(strict_types=1);

namespace Patrimeter\Security;

use Patrimeter\Domain\Account;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Second check of the isolation, on the object read from the database: only its owner can view or
 * change it. The repositories already filter by owner; this voter protects against a query that
 * would forget it. An account without an owner is never authorised.
 *
 * @extends Voter<string, Account>
 */
final class OwnedResourceVoter extends Voter
{
    public const string OWNER = 'OWNER';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::OWNER && $subject instanceof Account;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject->ownerId !== null && $subject->ownerId->equals($user->id);
    }
}
