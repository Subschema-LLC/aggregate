<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\MysqlTimeZoneTablesMissing;
use App\Service\ReportingTimezone;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Saves the reporting timezone for the event views. The form is a section of
 * General settings (ApplicationSettingsController); the old page address
 * redirects there. YAML and app:analytics:reporting-timezone are the headless path.
 */
final class ReportingTimezoneController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'reporting_timezone';
    /** A refused save: the submitted zone and why, shown once on General settings. */
    public const SESSION_REFUSED_SAVE = 'reporting_timezone_refused_save';
    public const SECTION = 'reporting-timezone';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly ReportingTimezone $timezone,
        private readonly AnalyticsPrivacySettings $thresholds,
        private readonly AnonymousBiViewManager $views,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** The setting moved to General settings; bookmarks and older links land on its section. */
    #[Route('/dashboard/reporting/timezone', name: 'app_reporting_timezone', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        return $this->section();
    }

    #[Route('/dashboard/reporting/timezone/save', name: 'app_reporting_timezone_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $form = $request->request->all();
        $token = $form['_csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->section();
        }

        $submitted = is_string($form['reporting_timezone'] ?? null) ? $form['reporting_timezone'] : '';
        try {
            $name = ReportingTimezone::validate($submitted);
            $this->views->assertReportingTimezoneCanBeCompiled($name);
            if ($this->timezone->hasEnvironmentOverride()) {
                $this->views->assertReportingTimezoneCanBeCompiled($this->timezone->name());
            }
        } catch (MysqlTimeZoneTablesMissing $e) {
            return $this->refused($request, $submitted, null, $e->command());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->refused($request, $submitted, $e->getMessage());
        }

        try {
            $this->timezone->save($name);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save the reporting timezone.', ['exception' => $e]);
            $this->addFlash('error', 'The reporting timezone could not be saved. Check the active YAML configuration and file permissions.');

            return $this->section();
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

        return $this->section();
    }

    /** Nothing was saved: General settings shows the submitted zone again with the reason or the MySQL command. */
    private function refused(Request $request, string $submitted, ?string $error, ?string $loadCommand = null): Response
    {
        $request->getSession()->set(self::SESSION_REFUSED_SAVE, [
            'value' => $submitted,
            'error' => $error,
            'load_command' => $loadCommand,
        ]);

        return $this->section();
    }

    private function section(): RedirectResponse
    {
        $response = $this->redirect($this->generateUrl('app_application_settings').'#'.self::SECTION);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
