<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Operations\AdminAudit;

/**
 * Keeps administrator actions in memory instead of the audit_trail table, for
 * route tests whose kernels have no database. Test kernels replace AdminAudit
 * with it; the tests read $entries.
 */
final class RecordingAdminAudit extends AdminAudit
{
    /** @var list<array{area: string, action: string, outcome: string, actor: ?string, subject: ?string, details: ?string, always: bool}> */
    public static array $entries = [];

    public function __construct()
    {
    }

    public function record(string $area, string $action, string $outcome, ?string $actor, ?string $subject = null, ?string $details = null, bool $always = false): void
    {
        self::$entries[] = ['area' => $area, 'action' => $action, 'outcome' => $outcome, 'actor' => $actor, 'subject' => $subject, 'details' => $details, 'always' => $always];
    }
}
