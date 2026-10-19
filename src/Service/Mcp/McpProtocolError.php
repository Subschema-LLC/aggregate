<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/** A JSON-RPC error answered to an MCP request: an unknown method, bad params and the like. */
final class McpProtocolError extends \RuntimeException
{
    public function __construct(public readonly int $rpcCode, string $message)
    {
        parent::__construct($message);
    }
}
