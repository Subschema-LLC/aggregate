<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\ReportingApi\ApiToken;
use App\Service\ReportingApi\ViewGrant;

/**
 * Who is calling the MCP server: a reporting API token with the MCP grant,
 * or an assistant connection an administrator approved. The grant decides
 * which views it may read; the label names it in the audit trail, never with
 * a secret.
 */
final readonly class McpPrincipal
{
    public const TOKEN = 'token';
    public const CONNECTION = 'connection';

    private function __construct(
        public string $kind,
        public string $id,
        public string $label,
        public ViewGrant $grant,
    ) {
    }

    public static function token(ApiToken $token): self
    {
        return new self(self::TOKEN, $token->id, sprintf('token %s (%s)', $token->id, $token->name), $token);
    }

    public static function connection(McpConnection $connection): self
    {
        return new self(self::CONNECTION, $connection->id, sprintf('assistant %s (%s, approved by %s)', $connection->id, $connection->clientName, $connection->approvedBy), $connection);
    }

    /** The rate limiter key: one budget per token or connection. */
    public function limiterKey(): string
    {
        return $this->kind.'-'.$this->id;
    }
}
