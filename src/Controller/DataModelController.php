<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\Glossary\GlossarySync;
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
        private readonly GlossarySync $glossary,
    ) {
    }

    #[Route('/dashboard/data-model', name: 'app_data_model', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        if (($request->query->all()['discover'] ?? null) === '1') {
            return $this->privateResponse($this->redirectToRoute('app_data_model_discovery', ['discover' => '1']));
        }
        $context = $this->loadModel();
        $model = $context['model'];
        $unsavedProperty = null;
        $additionError = null;
        $key = $request->query->all()['add_property'] ?? null;
        if ($key !== null && $context['configuration_error'] === null) {
            if (!is_string($key) || (!CustomDataSettings::isValidPropertyKey($key) && $key !== $context['marker_name'])) {
                $additionError = 'This property name cannot be added to the model.';
            } elseif (!array_key_exists($key, $model[CustomDataSettings::PROPERTIES_KEY])) {
                if (count($model[CustomDataSettings::PROPERTIES_KEY]) >= 50) {
                    $additionError = 'The model already contains the maximum of 50 properties.';
                } else {
                    $model[CustomDataSettings::PROPERTIES_KEY][$key] = $key === CustomDataSettings::PAGE_SEQUENCE_PROPERTY && $key !== $context['marker_name']
                        ? ['description' => 'Page depth; 20 means 20 or more.', 'consent_required' => false, 'type' => 'integer', 'column' => '']
                        : ['description' => '', 'consent_required' => true, 'column' => ''];
                    $model = $this->settings->validate($model);
                    $unsavedProperty = $key;
                }
            }
        }

        return $this->privateResponse($this->render('data_model/index.html.twig', [
            ...$context,
            'model' => $model,
            'unsaved_property' => $unsavedProperty,
            'addition_error' => $additionError,
            'detailed_anonymous_utms' => $this->detailedAnonymousUtms($model),
        ]));
    }

    #[Route('/dashboard/data-model/discovery', name: 'app_data_model_discovery', methods: ['GET'])]
    public function discovery(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $context = $this->loadModel();
        $requested = ($request->query->all()['discover'] ?? null) === '1';
        $properties = [];
        $reportingError = null;
        if ($requested && $context['configuration_error'] === null) {
            try {
                $properties = $this->views->discoverProperties();
                foreach ($properties as &$property) {
                    $property['can_add'] = CustomDataSettings::isValidPropertyKey($property['key']) || $property['key'] === $context['marker_name'];
                }
                unset($property);
            } catch (\Throwable $error) {
                $reportingError = 'Observed properties could not be loaded. Check database connectivity, migrations, and permissions.';
                $this->logFailure('discover', $error);
            }
        }

        return $this->privateResponse($this->render('data_model/discovery.html.twig', [
            ...$context,
            'observed_properties' => $properties,
            'discovery_requested' => $requested,
            'reporting_error' => $reportingError,
        ]));
    }

    #[Route('/dashboard/data-model/reporting', name: 'app_data_model_reporting', methods: ['GET'])]
    public function reporting(): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $context = $this->loadModel();
        $sql = [];
        $reportingError = null;
        if ($context['configuration_error'] === null) {
            try {
                $sql = $this->views->previewSql();
            } catch (\Throwable $error) {
                $reportingError = 'Reporting metadata could not be loaded. Check database connectivity, migrations, and permissions. The model can still be edited on its own page.';
                $this->logFailure('preview', $error);
            }
        }

        return $this->privateResponse($this->render('data_model/reporting.html.twig', [
            ...$context,
            'view_sql' => $sql,
            'reporting_error' => $reportingError,
        ]));
    }

    private function loadModel(): array
    {
        try {
            return [
                'model' => $this->settings->toArray(),
                'marker_name' => (new InternalTrafficSettings($this->config))->toBrowserConfig()['name'],
                'configuration_error' => null,
            ];
        } catch (\Throwable $error) {
            $this->logFailure('load', $error);

            return [
                'model' => CustomDataSettings::defaults(),
                'marker_name' => null,
                'configuration_error' => 'The custom data configuration is invalid. Correct its YAML before saving or regenerating views.',
            ];
        }
    }

    private function detailedAnonymousUtms(array $model): array
    {
        $properties = [];
        foreach ($model[CustomDataSettings::PROPERTIES_KEY] as $key => $definition) {
            if (!$definition['consent_required'] && in_array($key, CustomDataSettings::UTM_KEYS, true) && $key !== 'utm_medium') {
                $properties[$key] = true;
            }
        }
        foreach ($model[CustomDataSettings::MAPPINGS_KEY] as $source => $key) {
            if (in_array($source, CustomDataSettings::UTM_KEYS, true) && $source !== 'utm_medium'
                && !$model[CustomDataSettings::PROPERTIES_KEY][$key]['consent_required']) {
                $properties[$key] = true;
            }
        }

        return array_keys($properties);
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
            $this->addFlash('success', 'Data model saved. Collection settings apply to future events. Use the Reporting views page to apply reporting-column changes.');
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
            return $this->back('app_data_model_reporting');
        }
        try {
            $names = $this->views->regenerate();
            $this->addFlash('success', 'Regenerated '.implode(', ', $names).'.');
            try {
                $this->glossary->sync();
            } catch (\Throwable $error) {
                $this->logFailure('glossary_sync', $error);
                $this->addFlash('warning', 'Reporting views were regenerated, but the BI glossary was not synced. Run php bin/console app:analytics:glossary:sync after correcting its configuration or database issue.');
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->logFailure('regenerate', $e);
            $this->addFlash('error', 'Views could not be regenerated. Check database privileges, migrations, and JSON support. On MySQL/MariaDB, some view replacements may already have committed; correct the issue and retry.');
        }

        return $this->back('app_data_model_reporting');
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
        if (array_diff(array_keys($submitted), ['_csrf_token', 'properties', 'mappings', CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY, CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY]) !== []) {
            throw new \InvalidArgumentException('The model form contained an unexpected setting. Nothing was saved.');
        }
        $pageSequenceEnabled = array_key_exists(CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY, $submitted)
            ? $submitted[CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY] : '0';
        if (!in_array($pageSequenceEnabled, ['0', '1'], true)) {
            throw new \InvalidArgumentException('Choose whether page depth is enabled. Nothing was saved.');
        }
        $propertyRows = array_key_exists('properties', $submitted) ? $submitted['properties'] : [];
        $mappingRows = array_key_exists('mappings', $submitted) ? $submitted['mappings'] : [];
        if (!is_array($propertyRows) || !is_array($mappingRows) || count($propertyRows) > 51 || count($mappingRows) > 101) {
            throw new \InvalidArgumentException('The model supports up to 50 properties and 100 query mappings.');
        }
        $properties = [];
        foreach ($propertyRows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['key', 'description', 'column', 'consent_required', 'type', 'numeric_column']) !== []) {
                throw new \InvalidArgumentException('Invalid property row. Nothing was saved.');
            }
            foreach (['key', 'description', 'column', 'consent_required'] as $field) {
                if (!isset($row[$field]) || !is_string($row[$field])) {
                    throw new \InvalidArgumentException('Each property row must include its key, description, reporting column, and consent setting.');
                }
            }
            foreach (['type', 'numeric_column'] as $field) {
                if (array_key_exists($field, $row) && !is_string($row[$field])) {
                    throw new \InvalidArgumentException('Property types and numeric reporting columns must be strings.');
                }
            }
            $key = trim($row['key']);
            if ($key === '' && trim($row['description']) === '' && trim($row['column']) === '' && trim($row['numeric_column'] ?? '') === '') {
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
            if (($row['type'] ?? 'scalar') !== 'scalar') {
                $properties[$key]['type'] = $row['type'];
            }
            if (trim($row['numeric_column'] ?? '') !== '') {
                $properties[$key]['numeric_column'] = trim($row['numeric_column']);
            }
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

        $model = [
            CustomDataSettings::PROPERTIES_KEY => $properties,
            CustomDataSettings::MAPPINGS_KEY => $mappings,
            CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY => $pageSequenceEnabled === '1',
        ];
        // Older forms omit the method. Let the shared save service preserve
        // the current choice under its write lock instead of enabling storage.
        if (array_key_exists(CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY, $submitted)) {
            $model[CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY] = $submitted[CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY];
        }

        return $model;
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

    private function back(string $route = 'app_data_model'): Response
    {
        return $this->privateResponse($this->redirectToRoute($route));
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
