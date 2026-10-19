<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

/**
 * One reporting API token as ApiTokenStore keeps it: its public ID, name,
 * scope and dates, and the SHA-256 hash of the secret token. The token itself
 * is never stored.
 *
 * Scope follows ViewScope: $views null means every approved view, a list
 * means only those approved views, labels and definitions are always
 * readable, and private views are granted one by one in $privateViews.
 *
 * $mcp lets the token also authorize AI assistants on the MCP server
 * (docs/MCP.md). It is a separate grant, so a token made for a script does not
 * silently become an assistant credential.
 */
final readonly class ApiToken implements ViewGrant
{
    /**
     * @param list<string>|null $views        approved views, in catalog order, or null for every approved view
     * @param list<string>      $privateViews private views, in catalog order
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $hash,
        public ?array $views,
        public array $privateViews,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
        public ?\DateTimeImmutable $expiresAt,
        public bool $mcp = false,
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
}
