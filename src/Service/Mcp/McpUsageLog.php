<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\Operations\AuditTrail;
use App\Service\Operations\AuditTrailSettings;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Records each MCP tool call in the audit trail, area "assistant": which
 * credential called which tool on which view, how many rows came back and
 * how long it took. Never the results, filter values or the conversation;
 * the server never sees the conversation. A call is answered even when it
 * cannot be recorded.
 */
class McpUsageLog
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly LoggerInterface $logger,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? Clock::get();
    }

    /** @param list<string>|null $groupBy */
    public function record(McpPrincipal $principal, string $tool, ?string $view, bool $succeeded, int $rows, int $milliseconds, ?array $groupBy = null, ?string $error = null): void
    {
        $operation = in_array($tool, McpTools::NAMES, true) ? 'mcp_'.$tool : 'mcp_unknown_tool';
        $details = ['rows='.$rows, 'ms='.$milliseconds];
        if ($groupBy !== null) {
            $details[] = 'group_by='.($groupBy === [] ? '(total)' : implode(',', $groupBy));
        }
        if ($error !== null) {
            $details[] = 'error='.$error;
        }
        try {
            $this->audit->record(
                AuditTrailSettings::ASSISTANT,
                $operation,
                $succeeded ? 'succeeded' : 'failed',
                $this->clock->now(),
                $view,
                $principal->label,
                details: implode('; ', $details),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('An MCP tool call could not be recorded in the audit trail.', ['tool' => $tool, 'exception_class' => $e::class]);
        }
    }
}
