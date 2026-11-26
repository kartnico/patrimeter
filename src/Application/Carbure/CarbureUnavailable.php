<?php

declare(strict_types=1);

namespace Patrimeter\Application\Carbure;

/**
 * Carbure instance unreachable or unusable answer. The message is one of the reasons below, never the content of the
 * answer: Patrimeter does not send back what it reads at the address given by the user.
 */
final class CarbureUnavailable extends \RuntimeException
{
    public const string UNREACHABLE = 'Carbure ne répond pas à cette adresse.';
    public const string REFUSED = 'Carbure refuse le jeton d\'API : vérifiez qu\'il est toujours valable dans Carbure.';
    public const string NOT_FOUND = 'Aucun service MCP de Carbure à cette adresse : indiquez l\'adresse de l\'instance, et vérifiez que son serveur MCP est activé.';
    public const string INVALID = 'Réponse de Carbure illisible.';
    public const string THROTTLED = 'Trop de demandes à Carbure : réessayez dans quelques minutes.';
    public const string HOST_NOT_ALLOWED = 'Cette instance Carbure n\'est pas autorisée par l\'administrateur de Patrimeter.';
}
