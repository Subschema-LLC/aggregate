<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * A request the MCP endpoint refuses before it reaches the protocol: no or a
 * bad credential (401), too many requests (429), unreadable credentials on
 * the server (503), a foreign Origin (403) and the like. Answered as HTTP
 * with a short JSON body that never names a token.
 */
final class McpHttpError extends \RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
