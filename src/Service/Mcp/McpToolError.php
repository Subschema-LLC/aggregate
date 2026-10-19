<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * A tool call the assistant can correct: a wrong argument, an unknown view,
 * a view it may not read. Returned to the model as a tool result with
 * isError, never as a protocol error.
 */
final class McpToolError extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
