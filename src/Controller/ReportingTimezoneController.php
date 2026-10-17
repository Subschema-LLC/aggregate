<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\MysqlTimeZoneTables;
use App\Service\MysqlTimeZoneTablesMissing;
use App\Service\ReportingTimezone;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Reporting timezone for the event views; YAML and app:analytics:reporting-timezone are the headless path. */
final class ReportingTimezoneController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'reporting_timezone';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ReportingTimezone $timezone,
        private readonly AnalyticsPrivacySettings $thresholds,
        private readonly AnonymousBiViewManager $views,
        private readonly LoggerInterface $logger,
        private readonly MysqlTimeZoneTables $timeZones,
    ) {
    }

    #[Route('/dashboard/reporting/timezone', name: 'app_reporting_timezone', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        try {
            $name = $this->timezone->name();
            $error = null;
        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to load the reporting timezone.', ['exception' => $e]);
            $name = ReportingTimezone::DEFAULT;
            $error = $e->getMessage();
        }

        return $this->page($name, $error, timezoneLoadCommand: $this->loadCommandFor($name));
    }

    #[Route('/dashboard/reporting/timezone/save', name: 'app_reporting_timezone_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $form = $request->request->all();
        $token = $form['_csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_reporting_timezone');
        }

        $submitted = is_string($form['reporting_timezone'] ?? null) ? $form['reporting_timezone'] : '';
        try {
            $name = ReportingTimezone::validate($submitted);
            $this->views->assertReportingTimezoneCanBeCompiled($name);
            if ($this->timezone->hasEnvironmentOverride()) {
                $this->views->assertReportingTimezoneCanBeCompiled($this->timezone->name());
            }
        } catch (MysqlTimeZoneTablesMissing $e) {
            return $this->page($submitted, null, Response::HTTP_UNPROCESSABLE_ENTITY, $e->command());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->page($submitted, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->timezone->save($name);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save the reporting timezone.', ['exception' => $e]);
            $this->addFlash('error', 'The reporting timezone could not be saved. Check the active YAML configuration and file permissions.');

            return $this->redirectToRoute('app_reporting_timezone');
        }

        try {
            $thresholds = $this->thresholds->getMinimumCellCounts();
            $this->views->regenerate($thresholds['anonymous'], $thresholds['geo']);
            $this->addFlash('success', sprintf('Saved the reporting timezone %s and regenerated the BI views.', $name));
            if ($this->timezone->hasEnvironmentOverride()) {
                $this->addFlash('warning', 'REPORTING_TIMEZONE overrides the saved value. The views were regenerated with the environment value.');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to regenerate BI views after saving the reporting timezone.', ['exception' => $e]);
            $this->addFlash('warning', 'The reporting timezone was saved, but the BI views were not regenerated. Run php bin/console app:analytics:bi-views:regenerate after correcting the problem.');
        }

        return $this->redirectToRoute('app_reporting_timezone');
    }

    private function page(string $name, ?string $error, int $status = Response::HTTP_OK, ?string $timezoneLoadCommand = null): Response
    {
        return $this->render('settings/reporting_timezone.html.twig', [
            'reporting_timezone' => $name,
            'reporting_timezone_error' => $error,
            'timezone_choices' => ReportingTimezone::choices(),
            'environment_override' => $this->timezone->hasEnvironmentOverride(),
            'timezone_load_command' => $timezoneLoadCommand,
        ], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store, max-age=0']));
    }

    private function loadCommandFor(string $timezone): ?string
    {
        if (!$this->timeZones->applies() || ReportingTimezone::isIdentity($timezone) || $this->timeZones->converts($timezone)) {
            return null;
        }

        return $this->timeZones->operatorCommand();
    }
}
