<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\InternalTrafficSettings;
use App\Service\ReportingViewManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class DataModelController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'data_model_settings';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly CustomDataSettings $settings,
        private readonly ReportingViewManager $views,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/data-model', name: 'app_data_model', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $model = CustomDataSettings::defaults();
        $configurationError = null;
        $reportingError = null;
        $properties = [];
        $sql = [];
        $markerName = null;
        try {
            $model = $this->settings->toArray();
            $markerName = (new InternalTrafficSettings($this->config))->toBrowserConfig()['name'];
        } catch (\Throwable $e) {
            $configurationError = 'The custom data configuration is invalid. Correct its YAML before saving or regenerating views.';
            $this->logFailure('load', $e);
        }
        if ($configurationError === null) {
            try {
                $sql = $this->views->previewSql();
                if ($request->query->get('discover') === '1') {
                    $properties = $this->views->discoverProperties();
                    foreach ($properties as &$property) {
                        $property['can_add'] = CustomDataSettings::isValidPropertyKey($property['key']) || $property['key'] === $markerName;
                    }
                    unset($property);
                }
            } catch (\Throwable $e) {
                $reportingError = 'Reporting metadata could not be loaded. Check database connectivity, migrations, and permissions. The model can still be edited.';
                $this->logFailure('preview', $e);
            }
        }

        $detailedAnonymousUtms = [];
        foreach ($model[CustomDataSettings::PROPERTIES_KEY] as $key => $definition) {
            if (!$definition['consent_required'] && in_array($key, CustomDataSettings::UTM_KEYS, true) && $key !== 'utm_medium') {
                $detailedAnonymousUtms[$key] = true;
            }
        }
        foreach ($model[CustomDataSettings::MAPPINGS_KEY] as $source => $key) {
            if (in_array($source, CustomDataSettings::UTM_KEYS, true) && $source !== 'utm_medium'
                && !$model[CustomDataSettings::PROPERTIES_KEY][$key]['consent_required']) {
                $detailedAnonymousUtms[$key] = true;
            }
        }

        return $this->privateResponse($this->render('dashboard/data_model.html.twig', [
            'model' => $model,
            'configuration_error' => $configurationError,
            'reporting_error' => $reportingError,
            'observed_properties' => $properties,
            'discovery_requested' => $request->query->get('discover') === '1',
            'view_sql' => $sql,
            'marker_name' => $markerName,
            'detailed_anonymous_utms' => array_keys($detailedAnonymousUtms),
        ]));
    }

    #[Route('/dashboard/data-model/save', name: 'app_data_model_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        if (!$this->validToken($request)) {
            return $this->back();
        }
        try {
            $this->settings->save($this->modelFromForm($request->request->all()));
            $this->addFlash('success', 'Data model saved. Collection settings apply to future events. Regenerate views below to apply reporting-column changes.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logFailure('save', $e);
            $this->addFlash('error', 'The data model could not be saved. Check the active YAML configuration and file permissions.');
        }

        return $this->back();
    }

    #[Route('/dashboard/data-model/regenerate', name: 'app_data_model_regenerate', methods: ['POST'])]
    public function regenerate(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        if (!$this->validToken($request)) {
            return $this->back();
        }
        try {
            $names = $this->views->regenerate();
            $this->addFlash('success', 'Regenerated '.implode(', ', $names).'.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logFailure('regenerate', $e);
            $this->addFlash('error', 'Views could not be regenerated. Check database privileges, migrations, and JSON support. On MySQL/MariaDB, some view replacements may already have committed; correct the issue and retry.');
        }

        return $this->back();
    }

    #[Route('/dashboard/data-model/download', name: 'app_data_model_download', methods: ['GET'])]
    public function download(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        return $this->privateResponse(new Response($this->settings->exportYaml(), headers: [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="aggregate-data-model.yaml"',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    private function modelFromForm(array $submitted): array
    {
        if (array_diff(array_keys($submitted), ['_csrf_token', 'properties', 'mappings']) !== []) {
            throw new \InvalidArgumentException('The model form contained an unexpected setting. Nothing was saved.');
        }
        $propertyRows = array_key_exists('properties', $submitted) ? $submitted['properties'] : [];
        $mappingRows = array_key_exists('mappings', $submitted) ? $submitted['mappings'] : [];
        if (!is_array($propertyRows) || !is_array($mappingRows) || count($propertyRows) > 51 || count($mappingRows) > 101) {
            throw new \InvalidArgumentException('The model supports up to 50 properties and 100 query mappings.');
        }
        $properties = [];
        foreach ($propertyRows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['key', 'description', 'column', 'consent_required']) !== []) {
                throw new \InvalidArgumentException('Invalid property row. Nothing was saved.');
            }
            foreach (['key', 'description', 'column', 'consent_required'] as $field) {
                if (!isset($row[$field]) || !is_string($row[$field])) {
                    throw new \InvalidArgumentException('Each property row must include its key, description, reporting column, and consent setting.');
                }
            }
            $key = trim($row['key']);
            if ($key === '' && trim($row['description']) === '' && trim($row['column']) === '') {
                continue;
            }
            if (array_key_exists($key, $properties) || !in_array($row['consent_required'], ['0', '1'], true)) {
                throw new \InvalidArgumentException('Property keys must be unique and have a valid consent setting.');
            }
            $properties[$key] = [
                'description' => trim($row['description']),
                'column' => trim($row['column']),
                'consent_required' => $row['consent_required'] === '1',
            ];
        }
        $mappings = [];
        foreach ($mappingRows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['parameter', 'property']) !== []
                || !is_string($row['parameter'] ?? null) || !is_string($row['property'] ?? null)) {
                throw new \InvalidArgumentException('Each query mapping needs a parameter and a destination property.');
            }
            $parameter = trim($row['parameter']);
            $property = trim($row['property']);
            if ($parameter === '' && $property === '') {
                continue;
            }
            if (array_key_exists($parameter, $mappings)) {
                throw new \InvalidArgumentException('Each source parameter may appear only once. Multiple parameters can map to the same property.');
            }
            $mappings[$parameter] = $property;
        }

        return [CustomDataSettings::PROPERTIES_KEY => $properties, CustomDataSettings::MAPPINGS_KEY => $mappings];
    }

    private function validToken(Request $request): bool
    {
        $token = $request->request->all()['_csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return false;
        }

        return true;
    }

    private function back(): Response
    {
        return $this->privateResponse($this->redirectToRoute('app_data_model'));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function logFailure(string $operation, \Throwable $error): void
    {
        $this->logger->error('Custom data model operation failed.', ['operation' => $operation, 'exception_class' => $error::class]);
    }

    private function denyUnlessAvailableToAdmin(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }
}
