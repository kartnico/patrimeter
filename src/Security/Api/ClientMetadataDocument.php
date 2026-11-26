<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** Metadata document of an OAuth client, read by Symfony's serializer and checked by its constraints. */
final class ClientMetadataDocument
{
    /** @param list<string> $redirect_uris */
    public function __construct(
        public readonly string $client_id,
        #[Assert\Count(min: 1, max: 5)]
        #[Assert\All([new Assert\Callback([ClientMetadataDocuments::class, 'checkRedirect'])])]
        public readonly array $redirect_uris,
        public readonly ?string $client_name = null,
    ) {}
}
