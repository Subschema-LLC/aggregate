<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\ReportingApi\ViewGrant;
use App\Service\ReportingApi\ViewScope;

/**
 * One AI assistant connection as OAuthStore keeps it: an assistant an
 * administrator approved through the sign-in flow (docs/MCP.md#sign-in), the
 * views it may read, who approved it and until when, and the hashes of its
 * current access and refresh tokens. The tokens themselves are never stored.
 *
 * Scope follows ViewScope, exactly like a reporting API token.
 */
final readonly class McpConnection implements ViewGrant
{
    /**
     * @param list<string>|null $views        approved views, in catalog order, or null for every approved view
     * @param list<string>      $privateViews private views, in catalog order
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public ?array $views,
        public array $privateViews,
        public string $approvedBy,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $expiresAt,
        public string $accessHash,
        public \DateTimeImmutable $accessExpiresAt,
        public string $refreshHash,
        public ?string $previousRefreshHash,
    ) {
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function mayRead(string $view, bool $privateViewsAllowed): bool
    {
        return ViewScope::mayRead($this->views, $this->privateViews, $view, $privateViewsAllowed);
    }

    public function readableViews(bool $privateViewsAllowed): array
    {
        return ViewScope::readable($this->views, $this->privateViews, $privateViewsAllowed);
    }

    /**
     * Where the assistant comes from, for lists: the host of a client ID
     * metadata document, or "registered here" for a client that registered
     * itself, whose name it chose.
     */
    public function clientHost(): string
    {
        $host = parse_url($this->clientId, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'registered here';
    }
}
