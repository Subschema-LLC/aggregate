<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\Operations\BackgroundCommand;
use App\Service\Scheduler\Scheduler;
use App\Service\Scheduler\SchedulerSetup;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Scheduled tasks: whether the server runs app:scheduler:run, the exact
 * steps to set it up on this server, each job's schedule and last run, and a
 * button that runs the due jobs now. The jobs' own settings stay on their
 * pages; this page links to them.
 */
final class ScheduledTasksController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'scheduled_tasks';
    private const SESSION_RUN_REQUESTED = 'scheduler_run_requested_at';

    /** Each job's settings page and guide. */
    private const JOB_PAGES = [
        'analytics_maintenance' => ['route' => 'app_data_lifecycle', 'label' => 'Data lifecycle'],
        'bigquery_sync' => ['route' => 'app_bigquery', 'label' => 'BigQuery sync'],
        'reporting_database_sync' => ['route' => 'app_reporting_database', 'label' => 'Reporting database'],
        'forensic_database_sync' => ['route' => 'app_forensic_database', 'label' => 'Forensic database'],
    ];

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly Scheduler $scheduler,
        private readonly SchedulerSetup $setup,
        private readonly BackgroundCommand $background,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/scheduled-tasks', name: 'app_scheduled_tasks', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $jobs = [];
        foreach ($this->scheduler->jobs() as $job) {
            try {
                $status = $job->status();
            } catch (\Throwable $e) {
                $this->logger->error('Failed to read a scheduled job’s status.', ['job' => $job->name(), 'exception' => $e]);
                $status = ['enabled' => false, 'schedule' => '', 'last_run' => null, 'last_status' => null, 'next_due' => null,
                    'problem' => 'The status could not be read. Run the database migrations (php bin/console doctrine:migrations:migrate).'];
            }
            $jobs[] = ['name' => $job->name(), 'label' => $job->label(), 'page' => self::JOB_PAGES[$job->name()] ?? null] + $status;
        }
        $health = $this->scheduler->health();

        // After "Run now", the page waits for the background run to finish.
        $requested = $request->getSession()->get(self::SESSION_RUN_REQUESTED);
        $lastRun = $this->lastRun($health);
        if (!is_int($requested) || $requested < time() - 600 || ($lastRun !== null && $lastRun >= $requested && !$this->scheduler->isRunning())) {
            $request->getSession()->remove(self::SESSION_RUN_REQUESTED);
            $requested = null;
        }

        return $this->renderDashboardPage('scheduled_tasks/index.html.twig', [
            'health' => $health,
            'jobs' => $jobs,
            'plesk' => $this->setup->plesk(),
            'cron_line' => $this->setup->cronLine(),
            'manual_command' => $this->setup->manualCommand(),
            'late_after_minutes' => Scheduler::LATE_AFTER_MINUTES,
            'background_problem' => $this->background->problem(SchedulerSetup::COMMAND),
            'run_requested_at' => $requested,
            'running' => $this->scheduler->isRunning(),
        ]);
    }

    #[Route('/dashboard/scheduled-tasks/status', name: 'app_scheduled_tasks_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $this->denyUnlessPermitted();

        return new JsonResponse([
            'running' => $this->scheduler->isRunning(),
            'last_run' => $this->lastRun($this->scheduler->health()),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/dashboard/scheduled-tasks/run', name: 'app_scheduled_tasks_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $token = $request->request->get('_csrf_token');
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_scheduled_tasks');
        }
        try {
            $this->background->start(SchedulerSetup::COMMAND, ['--requested-by='.($this->getUser()?->getUserIdentifier() ?? 'administrator')], Scheduler::DIRECTORY.'/last-output.log');
            $request->getSession()->set(self::SESSION_RUN_REQUESTED, time());
            $this->addFlash('success', 'The due jobs are running. This page shows the results when they finish. This checks the jobs, not the server’s scheduled task.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_scheduled_tasks');
    }

    /** @param array{scheduled: ?array{started_at: \DateTimeImmutable}, manual: ?array{started_at: \DateTimeImmutable}} $health */
    private function lastRun(array $health): ?int
    {
        $times = array_filter([$health['scheduled']['started_at'] ?? null, $health['manual']['started_at'] ?? null]);

        return $times === [] ? null : max(array_map(static fn (\DateTimeImmutable $time): int => $time->getTimestamp(), $times));
    }
}
