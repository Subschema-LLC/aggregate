<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\Access\DashboardAccessVoter;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsPrivacySettings;
use App\Service\AnonymousBiViewManager;
use App\Service\CustomDataSettings;
use App\Service\Glossary\GlossarySync;
use App\Service\ReportingViewManager;
use App\Service\TrackerBuilds;
use App\Service\WebsiteScopeEditor;
use App\Service\WebsiteScopePublisher;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class DataModelController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'data_model_settings';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly CustomDataSettings $settings,
        private readonly ReportingViewManager $views,
        private readonly LoggerInterface $logger,
        private readonly GlossarySync $glossary,
        private readonly ?WebsiteScopeEditor $scopes = null,
        private readonly ?WebsiteScopePublisher $publisher = null,
        private readonly ?AnonymousBiViewManager $biViews = null,
        private readonly ?AnalyticsPrivacySettings $privacySettings = null,
    ) {
    }

    #[Route('/dashboard/data-model', name: 'app_data_model', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessPermitted();
        if (($request->query->all()['discover'] ?? null) === '1') {
            return $this->privateResponse($this->redirectToRoute('app_data_model_discovery', ['discover' => '1']));
        }
        $scope = null;
        $settings = $this->settings;
        if ($this->scopes !== null) {
            $rawScope = $request->query->get('scope');
            if ($rawScope === null && $request->cookies->has('aggregate_scope')) {
                $cookieScope = (string) $request->cookies->get('aggregate_scope');
                if ($cookieScope !== '' && $this->canUseScope($cookieScope)) {
                    $rawScope = $cookieScope;
                }
            }
            $scope = $this->permittedScopeSelection($this->scopes, $rawScope, 'app_data_model');
            $settings = new CustomDataSettings($this->scopes->configFor($scope));
        } elseif (!$this->canUseEveryWebsite()) {
            throw $this->createAccessDeniedException('The deployment data model needs access to every website.');
        }
        $context = $this->loadModel($settings);
        $model = $context['model'];
        $unsavedProperty = null;
        $builtinFocus = null;
        $additionError = null;
        $key = $request->query->all()['add_property'] ?? null;
        if ($key !== null && $context['configuration_error'] === null) {
            if (!is_string($key) || !CustomDataSettings::isValidPropertyKey($key)) {
                $additionError = 'This property name cannot be added to the model.';
            } elseif (CustomDataSettings::isBuiltin($key)) {
                // Built-in properties have their own box: only the column is chosen.
                $builtinFocus = $key;
            } elseif (!array_key_exists($key, $model[CustomDataSettings::PROPERTIES_KEY])) {
                if (count($model[CustomDataSettings::PROPERTIES_KEY]) >= 50) {
                    $additionError = 'The model already contains the maximum of 50 properties.';
                } else {
                    $model[CustomDataSettings::PROPERTIES_KEY][$key] = ['description' => '', 'consent_required' => true, 'column' => ''];
                    $model = $this->settings->validate($model);
                    $unsavedProperty = $key;
                }
            }
        }

        $restoredForm = false;
        $submittedBuiltins = null;
        if ($request->hasSession() && $request->getSession()->has('data_model_submitted_form')) {
            $submittedForm = $request->getSession()->remove('data_model_submitted_form');
            if (is_array($submittedForm)) {
                $model = $this->rehydrateModelFromSubmittedForm($model, $submittedForm);
                $submittedBuiltins = is_array($submittedForm['builtin'] ?? null) ? $submittedForm['builtin'] : null;
                $restoredForm = true;
            }
        }

        return $this->privateResponse($this->render('data_model/index.html.twig', [
            ...$context,
            'model' => $model,
            'custom_properties' => array_diff_key($model[CustomDataSettings::PROPERTIES_KEY], CustomDataSettings::BUILTIN_PROPERTIES),
            'builtin_properties' => $this->builtinProperties($context['model'], $submittedBuiltins),
            'builtin_focus' => $builtinFocus,
            'unsaved_property' => $unsavedProperty,
            'addition_error' => $additionError,
            'detailed_anonymous_utms' => $this->detailedAnonymousUtms($model),
            'reserved_columns' => CustomDataSettings::RESERVED_COLUMNS,
            'restored_form' => $restoredForm,
            'tracker_omit_unused_features' => (new TrackerBuilds($this->config))->enabled(),
            'scope' => $scope,
            'can_publish' => $this->publisher !== null && ($scope === null || $scope['kind'] === 'deployment') && $this->isGranted(DashboardAccessVoter::ROUTE, 'app_data_model_publish'),
        ]));
    }

    /**
     * The built-in properties box: whether each has a reporting column, and
     * its name. A failed save shows what was submitted.
     *
     * @return list<array{key: string, label: string, help: string, kind: string, included: bool, column: string, text_column: string}>
     */
    private function builtinProperties(array $model, ?array $submitted): array
    {
        $items = [];
        foreach (CustomDataSettings::BUILTIN_PROPERTIES as $key => $builtin) {
            $saved = $model[CustomDataSettings::PROPERTIES_KEY][$key] ?? null;
            $column = (string) ($saved[$builtin['alias']] ?? '');
            $included = $column !== '';
            if (is_array($submitted[$key] ?? null)) {
                $included = ($submitted[$key]['include'] ?? '0') === '1';
                $column = is_string($submitted[$key]['column'] ?? null) ? trim($submitted[$key]['column']) : $column;
            }
            $items[] = [
                'key' => $key,
                'label' => match ($key) {
                    CustomDataSettings::PAGE_SEQUENCE_PROPERTY => 'Page depth',
                    CustomDataSettings::ENTRY_CHANNEL_PROPERTY => 'Entry channel',
                    CustomDataSettings::WEBSITE_GROUP_PROPERTY => 'Website group',
                    default => 'Organization traffic',
                },
                'help' => match ($key) {
                    CustomDataSettings::PAGE_SEQUENCE_PROPERTY => '1 for the first tracked page in a browser tab, then 2, 3 and so on; 20 means 20 or more. Recorded while page depth is on.',
                    CustomDataSettings::ENTRY_CHANNEL_PROPERTY => 'How the visitor arrived in the browser tab: direct, search, social, email, referral or internal, kept for the pages that follow. Recorded while values are kept for the tab session.',
                    CustomDataSettings::WEBSITE_GROUP_PROPERTY => 'The display name of the website’s group from Website groups, or (ungrouped) when the website is not in a group. Added to both the approved bi_anonymous_* views and the private reporting views for roll-up reporting.',
                    default => 'true when the browser carries your team’s marker, false otherwise. Recorded on every event, so reports can leave out your own visits.',
                },
                'kind' => $builtin['alias'] === 'numeric_column' ? 'number' : 'text',
                'included' => $included,
                'column' => $column !== '' ? $column : $key,
                // An earlier text alias of page_sequence stays as it is.
                'text_column' => $builtin['alias'] === 'numeric_column' ? (string) ($saved['column'] ?? '') : '',
            ];
        }

        return $items;
    }

    #[Route('/dashboard/data-model/discovery', name: 'app_data_model_discovery', methods: ['GET'])]
    public function discovery(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $context = $this->loadModel();
        $requested = ($request->query->all()['discover'] ?? null) === '1';
        $properties = [];
        $reportingError = null;
        if ($requested && $context['configuration_error'] === null) {
            try {
                $properties = $this->views->discoverProperties();
                foreach ($properties as &$property) {
                    $property['can_add'] = CustomDataSettings::isValidPropertyKey($property['key']);
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
        $this->denyUnlessPermitted();
        $context = $this->loadModel();
        $sql = [];
        $status = null;
        $reportingError = null;
        if ($context['configuration_error'] === null) {
            try {
                $sql = $this->views->previewSql();
                $status = $this->views->status();
            } catch (\Throwable $error) {
                $reportingError = 'Reporting metadata could not be loaded. Check database connectivity, migrations, and permissions. The model can still be edited on its own page.';
                $this->logFailure('preview', $error);
            }
        }

        return $this->privateResponse($this->render('data_model/reporting.html.twig', [
            ...$context,
            'view_sql' => $sql,
            'view_status' => $status,
            'reporting_error' => $reportingError,
        ]));
    }

    private function loadModel(?CustomDataSettings $settings = null): array
    {
        $settings ??= $this->settings;
        try {
            return [
                'model' => $settings->toArray(),
                'configuration_error' => null,
            ];
        } catch (\Throwable $error) {
            $this->logFailure('load', $error);

            return [
                'model' => CustomDataSettings::defaults(),
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
                && isset($model[CustomDataSettings::PROPERTIES_KEY][$key])
                && !$model[CustomDataSettings::PROPERTIES_KEY][$key]['consent_required']) {
                $properties[$key] = true;
            }
        }

        return array_keys($properties);
    }

    #[Route('/dashboard/data-model/save', name: 'app_data_model_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $scope = (string) $request->query->get('scope', 'deployment');
        if (!$this->canUseScope($scope)) {
            throw $this->createNotFoundException('That website scope is not available.');
        }
        $params = $scope !== '' && $scope !== 'deployment' ? ['scope' => $scope] : [];
        if (!$this->validToken($request)) {
            return $this->back('app_data_model', $params);
        }
        $submitted = $request->request->all();
        $override = ($submitted['model_override'] ?? '0') === '1';
        unset($submitted['model_override']);
        try {
            $model = $this->modelFromForm($submitted);
            if ($this->scopes !== null && $params !== []) {
                $this->scopes->saveModel($scope, $model, $override);
                $this->addFlash('success', 'Data model saved for this scope. Collection uses it on future events. Reporting views still follow the deployment model until you publish its columns.');

                return $this->back('app_data_model', $params);
            }
            $this->settings->save($model);
        } catch (\Throwable $e) {
            if ($e instanceof \InvalidArgumentException) {
                $this->addFlash('error', $e->getMessage());
            } else {
                $this->logFailure('save', $e);
                $this->addFlash('error', 'The data model could not be saved. Check the active YAML configuration and file permissions.');
            }
            if ($request->hasSession()) {
                $request->getSession()->set('data_model_submitted_form', $request->request->all());
            }

            return $this->back('app_data_model', $params);
        }
        $this->applyToViews();

        return $this->back();
    }

    #[Route('/dashboard/data-model/publish', name: 'app_data_model_publish', methods: ['POST'])]
    public function publish(Request $request): Response
    {
        $this->denyUnlessPermitted();
        if (!$this->validToken($request)) {
            return $this->back();
        }
        if ($this->publisher === null) {
            $this->addFlash('error', 'Publishing website model columns is unavailable.');

            return $this->back();
        }
        try {
            $added = $this->publisher->publishModel();
        } catch (\InvalidArgumentException $error) {
            $this->addFlash('error', $error->getMessage());

            return $this->back();
        } catch (\Throwable $error) {
            $this->logFailure('publish', $error);
            $this->addFlash('error', 'Website model columns could not be published. Correct the scope files and try again.');

            return $this->back();
        }
        if ($added === []) {
            $this->addFlash('success', 'The deployment model already has every column used by a website or group model.');

            return $this->back();
        }
        $this->applyToViews('Published '.implode(', ', $added).' into the deployment data model.');

        return $this->back();
    }

    /**
     * After a save, brings the private reporting views up to date with the
     * model: creates them, or appends new columns. Removing a deployed
     * column drops grants, so that stays a deliberate step on the Reporting
     * views page.
     */
    private function applyToViews(string $saved = 'Data model saved. Collection changes apply to future events.'): void
    {
        try {
            $status = $this->views->status();
            if ($status['current']) {
                $this->addFlash('success', $saved.' The reporting views already match it.');

                return;
            }
            if ($status['removed'] !== []) {
                $this->addFlash('success', $saved);
                $this->addFlash('warning', 'The reporting views were not updated: '.implode(', ', $status['removed']).(count($status['removed']) === 1 ? ' is' : ' are').' no longer in the model. Removing a deployed column drops the views and their grants, so do it on purpose with Force recreate views on the Reporting views page, or restore the column.');

                return;
            }
            $this->views->regenerate();
            if ($this->biViews !== null && $this->privacySettings !== null && $this->settings->websiteGroupColumn() !== null) {
                $thresholds = $this->privacySettings->getMinimumCellCounts();
                $this->biViews->regenerate($thresholds['anonymous'], $thresholds['geo']);
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('success', $saved);
            $this->addFlash('warning', 'The reporting views were not updated: '.$e->getMessage());

            return;
        } catch (\Throwable $e) {
            $this->logFailure('regenerate', $e);
            $this->addFlash('success', $saved);
            $this->addFlash('warning', 'The reporting views could not be updated. Check database privileges, migrations and JSON support, then use Regenerate on the Reporting views page.');

            return;
        }
        $this->addFlash('success', $saved.' '.($status['missing'] === ReportingViewManager::VIEW_NAMES
            ? 'The reporting views were created.'
            : 'The reporting views were updated'.($status['added'] === [] ? '.' : ': added '.implode(', ', $status['added']).'.')));
        try {
            $this->glossary->sync();
        } catch (\Throwable $error) {
            $this->logFailure('glossary_sync', $error);
            $this->addFlash('warning', 'The reporting views were updated, but the BI glossary was not synced. Run php bin/console app:analytics:glossary:sync after correcting its configuration or database issue.');
        }
    }

    #[Route('/dashboard/data-model/regenerate', name: 'app_data_model_regenerate', methods: ['POST'])]
    public function regenerate(Request $request): Response
    {
        $this->denyUnlessPermitted();
        if (!$this->validToken($request)) {
            return $this->back('app_data_model_reporting');
        }
        $force = (bool) $request->request->get('force', false);
        try {
            $names = $this->views->regenerate($force);
            if ($this->biViews !== null && $this->privacySettings !== null && ($force || $this->settings->websiteGroupColumn() !== null)) {
                $thresholds = $this->privacySettings->getMinimumCellCounts();
                $this->biViews->regenerate($thresholds['anonymous'], $thresholds['geo'], $force);
            }
            $msg = $force
                ? 'Dropped and recreated '.implode(', ', $names).' with the updated column schema.'
                : 'Regenerated '.implode(', ', $names).'.';
            $this->addFlash('success', $msg);
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
        $this->denyUnlessPermitted();

        return $this->privateResponse(new Response($this->settings->exportYaml(), headers: [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="aggregate-data-model.yaml"',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    private function modelFromForm(array $submitted): array
    {
        if (array_diff(array_keys($submitted), ['_csrf_token', 'properties', 'builtin', 'mappings', CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY, CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY, CustomDataSettings::QUERY_PERSISTENCE_KEY]) !== []) {
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
        $properties = $this->withBuiltinProperties($properties, $submitted['builtin'] ?? null);
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
        // The same for query persistence: an older form keeps the saved choice.
        if (array_key_exists(CustomDataSettings::QUERY_PERSISTENCE_KEY, $submitted)) {
            $model[CustomDataSettings::QUERY_PERSISTENCE_KEY] = $submitted[CustomDataSettings::QUERY_PERSISTENCE_KEY];
        }

        return $model;
    }

    /**
     * Adds the built-in properties box to the submitted rows. A ticked box
     * sets that property's reporting column; an unticked one clears it. Other
     * fields of a saved definition, such as an earlier text alias of
     * page_sequence, stay. Forms without the box keep the saved definitions.
     *
     * @param array<string, array<string, mixed>> $properties
     *
     * @return array<string, array<string, mixed>>
     */
    private function withBuiltinProperties(array $properties, mixed $builtins): array
    {
        if ($builtins !== null && (!is_array($builtins) || array_diff(array_keys($builtins), array_keys(CustomDataSettings::BUILTIN_PROPERTIES)) !== [])) {
            throw new \InvalidArgumentException('Invalid built-in property settings. Nothing was saved.');
        }
        $saved = $this->settings->properties();
        foreach (CustomDataSettings::BUILTIN_PROPERTIES as $key => $builtin) {
            $row = $builtins[$key] ?? null;
            if ($row === null) {
                // An older form, or an API client, lists them as ordinary rows.
                if (!isset($properties[$key]) && isset($saved[$key])) {
                    $properties[$key] = $saved[$key];
                }
                continue;
            }
            if (isset($properties[$key])) {
                throw new \InvalidArgumentException($key.' is built in: choose its reporting column under Built-in properties instead of adding it as a property. Nothing was saved.');
            }
            if (!is_array($row) || array_diff(array_keys($row), ['include', 'column']) !== []
                || !in_array($row['include'] ?? '0', ['0', '1'], true) || !is_string($row['column'] ?? '')) {
                throw new \InvalidArgumentException('Invalid built-in property settings. Nothing was saved.');
            }
            $column = trim((string) ($row['column'] ?? ''));
            if (($row['include'] ?? '0') === '1') {
                $properties[$key] = [...($saved[$key] ?? CustomDataSettings::builtinDefinition($key, '')), $builtin['alias'] => $column !== '' ? $column : $key];
            } elseif (isset($saved[$key])) {
                $properties[$key] = $saved[$key];
                unset($properties[$key][$builtin['alias']]);
                if ($builtin['alias'] === 'column') {
                    $properties[$key]['column'] = '';
                }
            }
        }

        return $properties;
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

    private function back(string $route = 'app_data_model', array $params = []): Response
    {
        return $this->privateResponse($this->redirectToRoute($route, $params));
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

    /**
     * Rehydrates user-submitted property rows, descriptions, types, and column names
     * when validation fails so that keyed-in values are not lost on refresh/redirect.
     */
    private function rehydrateModelFromSubmittedForm(array $currentModel, array $submitted): array
    {
        $properties = [];
        $propertyRows = is_array($submitted['properties'] ?? null) ? $submitted['properties'] : [];
        $rowCounter = 0;
        foreach ($propertyRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowCounter++;
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '' && trim((string) ($row['description'] ?? '')) === '' && trim((string) ($row['column'] ?? '')) === '' && trim((string) ($row['numeric_column'] ?? '')) === '') {
                continue;
            }

            $displayKey = $key !== '' ? $key : ('new_property_' . $rowCounter);
            $arrayKey = $displayKey;
            $suffix = 2;
            while (isset($properties[$arrayKey])) {
                $arrayKey = $displayKey . '_' . $suffix;
                $suffix++;
            }

            $properties[$arrayKey] = [
                'original_key' => $key,
                'description' => (string) ($row['description'] ?? ''),
                'column' => trim((string) ($row['column'] ?? '')),
                'numeric_column' => trim((string) ($row['numeric_column'] ?? '')),
                'consent_required' => ($row['consent_required'] ?? '1') === '1',
                'type' => (string) ($row['type'] ?? 'scalar'),
            ];
        }

        $mappings = [];
        $mappingRows = is_array($submitted['mappings'] ?? null) ? $submitted['mappings'] : [];
        foreach ($mappingRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $param = trim((string) ($row['parameter'] ?? ''));
            $prop = trim((string) ($row['property'] ?? ''));
            if ($param !== '') {
                $mappings[$param] = $prop;
            }
        }

        return [
            CustomDataSettings::PROPERTIES_KEY => $properties !== [] ? $properties : $currentModel[CustomDataSettings::PROPERTIES_KEY],
            CustomDataSettings::MAPPINGS_KEY => $mappingRows !== [] ? $mappings : $currentModel[CustomDataSettings::MAPPINGS_KEY],
            CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY => ($submitted[CustomDataSettings::PAGE_SEQUENCE_ENABLED_KEY] ?? '0') === '1',
            CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY => (string) ($submitted[CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY] ?? $currentModel[CustomDataSettings::PAGE_SEQUENCE_METHOD_KEY]),
            CustomDataSettings::QUERY_PERSISTENCE_KEY => is_string($submitted[CustomDataSettings::QUERY_PERSISTENCE_KEY] ?? null)
                ? $submitted[CustomDataSettings::QUERY_PERSISTENCE_KEY] : $currentModel[CustomDataSettings::QUERY_PERSISTENCE_KEY],
        ];
    }
}
