<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewSyncBackground;
use App\Service\ViewSync\ViewSyncDestinations;
use App\Service\ViewSync\ViewSyncRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the admin pages of the two view syncs, BigQuery and the reporting
 * database, do the same way: the sync status, choosing the views, the
 * schedule and "Sync now". Both pages are tabs of the dashboard's Sync page.
 * Names and messages come from the sync's definition
 * (ViewSyncSettings::definition()).
 *
 * The using controller uses DashboardAccess and implements the abstract
 * methods below. It also defines the constants ROUTE, CSRF_TOKEN_ID and
 * SESSION_SYNC_REQUESTED; PHP has no abstract constants, and PHPStan reports
 * one that is missing.
 */
trait ViewSyncAdmin
{
    /**
     * The sync this page runs; its settings are the page's.
     *
     * @return ViewSyncRunner<*>
     */
    abstract private function syncRunner(): ViewSyncRunner;

    abstract private function syncBackground(): ViewSyncBackground;

    abstract private function syncLogger(): LoggerInterface;

    /** Every destination, for the Sync page's tabs. */
    abstract private function syncDestinations(): ViewSyncDestinations;

    /**
     * The settings, or the defaults with the reason the configured ones
     * cannot be used.
     *
     * @return array{array<string, mixed>, ?string}
     */
    private function loadSyncSettings(): array
    {
        $settings = $this->syncRunner()->settings();
        try {
            return [$settings->toArray(), null];
        } catch (\Throwable $e) {
            $name = $settings::definition()->settingsName;
            $this->syncLogger()->error('Failed to load '.$name.' settings.', ['exception' => $e]);

            return [
                $settings::validate([]),
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
    private function syncPage(Request $request, array $settings, ?string $error): array
    {
        $runner = $this->syncRunner();
        try {
            $status = $runner->status($settings);
        } catch (\Throwable $e) {
            $this->syncLogger()->error('Failed to read '.$runner->settings()::definition()->settingsName.' sync status.', ['exception' => $e]);
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
            'overrides' => $runner->settings()->getEnvironmentOverrides(),
            'configuration_error' => $error,
            'approved_views' => ViewCatalog::APPROVED,
            'private_views' => ViewCatalog::PRIVATE,
            'intervals' => $runner->settings()::INTERVALS,
            'status' => $status,
            'sync_requested_at' => $requested,
            'background_problem' => $this->syncBackground()->problem(),
            'cron_line' => $this->syncBackground()->cronLine(),
            'sync_destinations' => $this->syncDestinations()->all(),
        ];
    }

    /** Whether a sync is running, for the page to poll after "Sync now". */
    private function syncStatusResponse(): JsonResponse
    {
        $this->denyUnlessPermitted();
        $runner = $this->syncRunner();
        try {
            $status = $runner->status($runner->settings()->toArray());
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
     */
    private function saveSyncViews(Request $request): Response
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
        $settings = $this->syncRunner()->settings();
        $sync = $settings::definition();
        $keys = $settings::keys();
        try {
            $current = $settings->toArray()[$keys['private_views']];
            $added = array_diff(array_map('strval', $private), $current);
            if ($added !== [] && $request->request->get('private_views_confirmed') !== '1') {
                throw new \InvalidArgumentException('Confirm that the private views you selected may be copied to '.$sync->destination.'. They hold row-level or unsuppressed data.');
            }
            $settings->save([
                $keys['views'] => array_values(array_map('strval', $views)),
                $keys['private_views'] => array_values(array_map('strval', $private)),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute(self::ROUTE);
        } catch (\Throwable $e) {
            $this->syncLogger()->error('Failed to save '.$sync->settingsName.' views.', ['exception' => $e]);
            $this->addFlash('error', 'The views could not be saved. Check the application log and configuration-file permissions.');

            return $this->redirectToRoute(self::ROUTE);
        }
        $this->addFlash('success', $sync->viewsSaved);

        return $this->redirectToRoute(self::ROUTE);
    }

    /** Saves whether the sync is on and its interval. */
    private function saveSyncSchedule(Request $request): Response
    {
        if (($response = $this->guard($request, ['enabled', 'interval'])) !== null) {
            return $response;
        }
        $settings = $this->syncRunner()->settings();
        $keys = $settings::keys();
        try {
            $settings->save(array_filter([
                $keys['enabled'] => $request->request->get('enabled', '0'),
                $keys['interval'] => $request->request->get('interval'),
            ], static fn (mixed $value): bool => $value !== null));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute(self::ROUTE);
        } catch (\Throwable $e) {
            $this->syncLogger()->error('Failed to save the '.$settings::definition()->settingsName.' schedule.', ['exception' => $e]);
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
            $this->syncBackground()->start($this->getUser()?->getUserIdentifier() ?? 'administrator');
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
        $this->denyUnlessPermitted();
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
