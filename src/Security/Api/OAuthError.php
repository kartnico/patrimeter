<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

use Symfony\Component\HttpFoundation\Response;

/** An OAuth error answered as JSON (RFC 6749 § 5.2, RFC 7591 § 3.2.2): its code, its description, its HTTP status. */
final class OAuthError extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly string $description,
        public readonly int $status = Response::HTTP_BAD_REQUEST,
    ) {
        parent::__construct($description);
    }

    /** @return array{error: string, error_description: string} */
    public function body(): array
    {
        return ['error' => $this->error, 'error_description' => $this->description];
    }
}
