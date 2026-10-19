<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BigQuery\BigQueryViewCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the admin pages of the two view syncs, BigQuery and the reporting
 * database, do the same way: the sync status, choosing the views, the
 * schedule and "Sync now". The using controller uses DashboardAccess, defines
 * ROUTE, CSRF_TOKEN_ID and SESSION_SYNC_REQUESTED, and has its sync's
 * ViewSyncSettings, ViewSyncRunner and ViewSyncBackground in $this->settings,
 * $this->runner and $this->background, and a logger in $this->logger.
 *
 * $name names the sync in log messages and page errors, as in "Failed to load
 * BigQuery settings."
 */
trait ViewSyncAdmin
{
    /**
     * The settings, or the defaults with the reason the configured ones
     * cannot be used.
     *
     * @return array{array<string, mixed>, ?string}
     */
    private function loadSyncSettings(string $name): array
    {
        try {
            return [$this->settings->toArray(), null];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load '.$name.' settings.', ['exception' => $e]);

            return [
                $this->settings::validate([]),
                'The '.$name.' settings in YAML or the environment are invalid: '.($e instanceof \InvalidArgumentException ? $e->getMessage() : 'see the application log.').' Correct them before syncing.',
            ];
        }
    }

    /**
     * The page parameters every view sync has: settings, views, schedule and
     * status, and the pending "Sync now" request.
     *
     * @param array<string, mixed> $settings from loadSyncSettings()
     * @param ?string              $error    from loadSyncSettings()
     *
     * @return array<string, mixed>
     */
    private function syncPage(Request $request, string $name, array $settings, ?string $error): array
    {
        try {
            $status = $this->runner->status($settings);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to read '.$name.' sync status.', ['exception' => $e]);
            $status = ['last_run' => null, 'views' => [], 'scheduler_late' => false];
            $error ??= 'The sync status could not be read. Run the database migrations (php bin/console doctrine:migrations:migrate).';
        }
        // After “Sync now”, the page waits for the background run to start and finish.
        $requested = $request->getSession()->get(self::SESSION_SYNC_REQUESTED);
        $lastRun = $status['last_run'];
        if (!is_int($requested) || $requested < time() - 600 || ($lastRun !== null && $lastRun->getTimestamp() >= $requested && !in_array('running', array_column($status['views'], 'status'), true))) {
            $request->getSession()->remove(self::SESSION_SYNC_REQUESTED);
            $requested = null;
        }

        return [
            'settings' => $settings,
            'overrides' => $this->settings->getEnvironmentOverrides(),
            'configuration_error' => $error,
            'approved_views' => BigQueryViewCatalog::APPROVED,
            'private_views' => BigQueryViewCatalog::PRIVATE,
            'intervals' => $this->settings::INTERVALS,
            'status' => $status,
            'sync_requested_at' => $requested,
            'background_problem' => $this->background->problem(),
            'cron_line' => $this->background->cronLine(),
        ];
    }

    /** Whether a sync is running, for the page to poll after "Sync now". */
    private function syncStatusResponse(): JsonResponse
    {
        $this->denyUnlessAvailableToAdmin();
        try {
            $status = $this->runner->status($this->settings->toArray());
        } catch (\Throwable) {
            return new JsonResponse(['running' => false, 'last_run' => null, 'views' => []], headers: ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'running' => in_array('running', array_column($status['views'], 'status'), true),
            'last_run' => $status['last_run']?->getTimestamp(),
            'views' => array_map(static fn (array $view): array => ['view' => $view['view'], 'status' => $view['status']], $status['views']),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Saves the approved and private views. Adding a private view needs the
     * form's confirmation.
     *
     * @param string $destination as in "may be copied to BigQuery"
     * @param string $saved       the success message
     */
    private function saveSyncViews(Request $request, string $name, string $destination, string $saved): Response
    {
        if (($response = $this->guard($request, ['views', 'private_views', 'private_views_confirmed'])) !== null) {
            return $response;
        }
        $views = $request->request->all()['views'] ?? [];
        $private = $request->request->all()['private_views'] ?? [];
        if (!is_array($views) || !is_array($private)) {
            $this->addFlash('error', 'The form contained an unexpected value. Nothing was saved.');

            return $this->redirectToRoute(self::ROUTE);
        }
        $keys = $this->settings::keys();
        try {
            $current = $this->settings->toArray()[$keys['private_views']];
            $added = array_diff(array_map('strval', $private), $current);
            if ($added !== [] && $request->request->get('private_views_confirmed') !== '1') {
                throw new \InvalidArgumentException('Confirm that the private views you selected may be copied to '.$destination.'. They hold row-level or unsuppressed data.');
            }
            $this->settings->save([
                $keys['views'] => array_values(array_map('strval', $views)),
                $keys['private_views'] => array_values(array_map('strval', $private)),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute(self::ROUTE);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save '.$name.' views.', ['exception' => $e]);
            $this->addFlash('error', 'The views could not be saved. Check the application log and configuration-file permissions.');

            return $this->redirectToRoute(self::ROUTE);
        }
        $this->addFlash('success', $saved);

        return $this->redirectToRoute(self::ROUTE);
    }

    /** Saves whether the sync is on and its interval. */
    private function saveSyncSchedule(Request $request, string $name): Response
    {
        if (($response = $this->guard($request, ['enabled', 'interval'])) !== null) {
            return $response;
        }
        $keys = $this->settings::keys();
        try {
            $this->settings->save(array_filter([
                $keys['enabled'] => $request->request->get('enabled', '0'),
                $keys['interval'] => $request->request->get('interval'),
            ], static fn (mixed $value): bool => $value !== null));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute(self::ROUTE);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save the '.$name.' schedule.', ['exception' => $e]);
            $this->addFlash('error', 'The schedule could not be saved. Check the application log and configuration-file permissions.');

            return $this->redirectToRoute(self::ROUTE);
        }
        $this->addFlash('success', 'The sync schedule was saved.');

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * Starts the sync in the background for the signed-in administrator.
     *
     * @param \Closure(): void $ready throws \RuntimeException when the sync cannot start
     */
    private function startSync(Request $request, \Closure $ready): Response
    {
        if (($response = $this->guard($request)) !== null) {
            return $response;
        }
        try {
            $ready();
            $this->background->start($this->getUser()?->getUserIdentifier() ?? 'administrator');
            $request->getSession()->set(self::SESSION_SYNC_REQUESTED, time());
            $this->addFlash('success', 'The sync has started. This page shows each view’s result when it finishes.');
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    /** Admin, dashboard and CSRF checks for form posts; only the listed fields may be submitted. @param list<string> $fields */
    private function guard(Request $request, array $fields = []): ?Response
    {
        $this->denyUnlessAvailableToAdmin();
        $submitted = $request->request->all();
        $token = $submitted['_csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute(self::ROUTE);
        }
        if (array_diff(array_keys($submitted), ['_csrf_token', ...$fields]) !== []) {
            $this->addFlash('error', 'The form contained an unexpected setting. Nothing was saved.');

            return $this->redirectToRoute(self::ROUTE);
        }

        return null;
    }
}
