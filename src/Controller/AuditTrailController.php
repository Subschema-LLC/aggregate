<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventSubscriber\AdminAuditSubscriber;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\Operations\AdminActions;
use App\Service\Operations\AdminAudit;
use App\Service\Operations\AuditTrailFilter;
use App\Service\Operations\AuditTrailLog;
use App\Service\Operations\AuditTrailSettings;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Audit trail page: the entries, filtered by date, area, action, outcome,
 * user, subject, text and job run; a CSV export of the filtered entries; and
 * the switches that choose what is recorded. Administrators only.
 */
final class AuditTrailController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'audit_trail_settings';

    /** @var array<string, string> */
    public const OUTCOMES = [
        AdminAudit::SUCCEEDED => 'Succeeded',
        AdminAudit::FAILED => 'Failed',
        AdminAudit::DENIED => 'Denied',
        AdminAudit::STARTED => 'Started',
    ];

    /** Quick date ranges: label => days, today included. */
    private const RANGES = ['Today' => 1, 'Last 7 days' => 7, 'Last 30 days' => 30, 'Last 90 days' => 90, 'Last year' => 365];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly AuditTrailLog $log,
        private readonly AuditTrailSettings $settings,
        private readonly AnalyticsDataLifecyclePolicy $policy,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/audit-trail', name: 'app_audit_trail', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $filter = AuditTrailFilter::fromQuery($request->query->all(), $problems);
        $result = null;
        $choices = ['areas' => [], 'actions' => [], 'outcomes' => [], 'users' => [], 'without_user' => false];
        $summary = [];
        $readError = null;
        try {
            $result = $this->log->search($filter);
            $choices = $this->log->choices();
            $summary = $this->log->summary();
        } catch (\Throwable $e) {
            $this->logger->error('The audit trail could not be read.', ['exception' => $e]);
            $readError = 'The audit trail could not be read. Run the database migrations (php bin/console doctrine:migrations:migrate) and check the database connection.';
        }

        $switches = AuditTrailSettings::defaults();
        $switchesError = null;
        try {
            $switches = $this->settings->all();
        } catch (\Throwable $e) {
            $this->logger->error('The audit trail switches could not be read.', ['exception' => $e]);
            $switchesError = sprintf('%s in the YAML configuration is invalid, so every area is recorded as by default. Correct it before saving: %s', AuditTrailSettings::KEY, $e instanceof \InvalidArgumentException ? $e->getMessage() : 'see the application log.');
        }

        $retention = null;
        try {
            $policy = $this->policy->getPolicy();
            $retention = ['job_runs' => $policy['audit_trail_retention_days'], 'admin' => $policy['audit_trail_admin_retention_days']];
        } catch (\Throwable) {
            // The Data lifecycle page reports an invalid policy.
        }

        $actions = [];
        foreach (array_unique([...$choices['actions'], ...$filter->actions]) as $action) {
            $actions[$action] = AdminActions::label($action);
        }
        asort($actions);
        $outcomes = self::OUTCOMES;
        foreach ([...$choices['outcomes'], ...$filter->outcomes] as $outcome) {
            $outcomes[$outcome] ??= AdminActions::label($outcome);
        }
        $users = $choices['users'];
        if ($filter->user !== null && $filter->user !== AuditTrailFilter::NO_USER && !in_array($filter->user, $users, true)) {
            $users[] = $filter->user;
        }

        return $this->renderDashboardPage('audit_trail/index.html.twig', [
            'filter' => $filter,
            'query' => $filter->toQuery(false),
            'problems' => $problems,
            'result' => $result,
            'read_error' => $readError,
            'areas' => AuditTrailSettings::AREAS,
            'actions' => $actions,
            'outcomes' => $outcomes,
            'users' => $users,
            'without_user' => $choices['without_user'],
            'no_user' => AuditTrailFilter::NO_USER,
            'page_sizes' => AuditTrailFilter::PAGE_SIZES,
            'ranges' => $this->ranges(),
            'summary' => $summary,
            'switches' => $switches,
            'switches_error' => $switchesError,
            'retention' => $retention,
            'export_limit' => AuditTrailLog::MAX_EXPORT,
        ]);
    }

    /** The filtered entries as CSV, in the page's order, up to AuditTrailLog::MAX_EXPORT. */
    #[Route('/dashboard/audit-trail/export', name: 'app_audit_trail_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $filter = AuditTrailFilter::fromQuery($request->query->all());
        $names = $filter->filterNames();
        $request->attributes->set(AdminAuditSubscriber::DETAILS, $names === [] ? 'All entries.' : 'Filtered by '.implode(', ', $names).'.');
        try {
            // Read before the download starts, so a database problem is shown on the page.
            $this->log->search($filter->withPage(1));
        } catch (\Throwable $e) {
            $this->logger->error('The audit trail could not be exported.', ['exception' => $e]);
            $this->addFlash('error', 'The audit trail could not be read. Run the database migrations and check the database connection.');

            return $this->redirectToRoute('app_audit_trail', $filter->toQuery());
        }
        $log = $this->log;
        $logger = $this->logger;
        $response = new StreamedResponse(static function () use ($log, $logger, $filter): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }
            fwrite($output, "\u{FEFF}");
            fputcsv($output, ['Time (UTC)', 'Area', 'Action', 'Outcome', 'User', 'Subject', 'Details', 'Job run', 'Entry'], ',', '"', '');
            try {
                foreach ($log->each($filter) as $entry) {
                    fputcsv($output, array_map(self::csvCell(...), [
                        $entry['occurred_at']->format('Y-m-d H:i:s'),
                        AuditTrailSettings::AREAS[$entry['area']]['label'] ?? $entry['area'],
                        AdminActions::label($entry['action']),
                        self::OUTCOMES[$entry['outcome']] ?? $entry['outcome'],
                        $entry['user'] ?? '',
                        $entry['subject'] ?? '',
                        $entry['details'] ?? '',
                        $entry['task'] !== null ? (string) $entry['task'] : '',
                        (string) $entry['id'],
                    ]), ',', '"', '');
                }
            } catch (\Throwable $e) {
                $logger->error('The audit trail export stopped early.', ['exception' => $e]);
                fputcsv($output, ['The export stopped early because the audit trail could not be read. See the application log.'], ',', '"', '');
            }
            fclose($output);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="audit-trail-'.gmdate('Ymd-His').'.csv"');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/dashboard/audit-trail/settings', name: 'app_audit_trail_settings', methods: ['POST'])]
    public function saveSettings(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $submitted = $request->request->all();
        $csrf = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrf) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $csrf)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_audit_trail', ['_fragment' => 'audit-switches']);
        }
        // A ticked box sends record[area]=1; an area left out is not recorded.
        $record = $submitted['record'] ?? [];
        if (array_diff(array_keys($submitted), ['_csrf_token', 'record']) !== [] || !is_array($record)
            || array_diff(array_keys($record), array_keys(AuditTrailSettings::AREAS)) !== []
            || array_filter($record, static fn (mixed $value): bool => $value !== '1') !== []) {
            $this->addFlash('error', 'The form does not match the audit trail areas. Reload this page and try again. Nothing was saved.');

            return $this->redirectToRoute('app_audit_trail', ['_fragment' => 'audit-switches']);
        }
        $changes = [];
        foreach (array_keys(AuditTrailSettings::AREAS) as $area) {
            $changes[$area] = isset($record[$area]);
        }

        try {
            $current = AuditTrailSettings::defaults();
            try {
                $current = $this->settings->all();
            } catch (\InvalidArgumentException) {
                // Saving replaces the invalid value.
            }
            $this->settings->save($changes);
            $on = array_keys(array_filter($changes, static fn (bool $recorded, string $area): bool => $recorded && !$current[$area], ARRAY_FILTER_USE_BOTH));
            $off = array_keys(array_filter($changes, static fn (bool $recorded, string $area): bool => !$recorded && $current[$area], ARRAY_FILTER_USE_BOTH));
            $request->attributes->set(AdminAuditSubscriber::DETAILS, implode(' ', array_filter([
                $on !== [] ? 'Turned on: '.implode(', ', $on).'.' : null,
                $off !== [] ? 'Turned off: '.implode(', ', $off).'.' : null,
            ])) ?: 'No area changed.');
            $this->addFlash('success', 'Saved what the audit trail records. Changes to these switches are always recorded.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('The audit trail switches could not be saved.', ['exception' => $e]);
            $this->addFlash('error', 'The audit trail switches could not be saved. Check the YAML configuration and its file permissions.');
        }

        return $this->redirectToRoute('app_audit_trail', ['_fragment' => 'audit-switches']);
    }

    /**
     * A spreadsheet must not run a cell as a formula: values starting with
     * =, +, -, @ or a control character are prefixed with an apostrophe.
     */
    public static function csvCell(string $value): string
    {
        return $value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** @return array<string, array{from: string, to: string}> */
    private function ranges(): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $ranges = [];
        foreach (self::RANGES as $label => $days) {
            $ranges[$label] = ['from' => $today->modify('-'.($days - 1).' days')->format('Y-m-d'), 'to' => $today->format('Y-m-d')];
        }

        return $ranges;
    }
}
