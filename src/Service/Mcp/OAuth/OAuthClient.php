<?php

declare(strict_types=1);

namespace App\Service\Mcp\OAuth;

/**
 * An assistant's OAuth client: registered here through dynamic client
 * registration (RFC 7591), or described by a client ID metadata document its
 * maker publishes at the HTTPS URL that is its client ID. Every client is
 * public: it proves itself with PKCE, never a secret.
 */
final readonly class OAuthClient
{
    public const REGISTERED = 'registered';
    public const METADATA_DOCUMENT = 'metadata_document';

    /** @param list<string> $redirectUris */
    public function __construct(
        public string $id,
        public string $name,
        public array $redirectUris,
        public string $kind,
    ) {
    }

    public function allowsRedirect(string $redirectUri): bool
    {
        foreach ($this->redirectUris as $registered) {
            if (RedirectUri::matches($registered, $redirectUri)) {
                return true;
            }
        }

        return false;
    }
}
