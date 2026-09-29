<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\Glossary\BiGlossarySettings;
use App\Service\Glossary\EventNameSuggestions;
use App\Service\Glossary\GlossaryResolver;
use App\Service\Glossary\GlossarySync;
use App\Service\Glossary\GlossaryValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

final class BiGlossaryController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'bi_glossary';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly BiGlossarySettings $settings,
        private readonly GlossaryResolver $resolver,
        private readonly GlossarySync $sync,
        private readonly EventNameSuggestions $suggestions,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/data-model/glossary', name: 'app_bi_glossary', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();

        return $this->page($request);
    }

    #[Route('/dashboard/data-model/glossary/save', name: 'app_bi_glossary_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        if (!$this->validToken($request)) {
            return $this->back($request);
        }
        try {
            $this->settings->save($this->settingsFromForm($request->request->all()));
        } catch (GlossaryValidationException $error) {
            return $this->page($request, $error->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException $error) {
            return $this->page($request, ['bi_glossary' => $error->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $error) {
            $this->logFailure('save', $error);
            $this->addFlash('error', 'The glossary could not be saved. Check the active YAML configuration and file permissions.');

            return $this->back($request);
        }
        try {
            $this->sync->sync();
            $this->addFlash('success', 'BI glossary saved and synced.');
        } catch (\Throwable $error) {
            $this->logFailure('sync_after_save', $error);
            $this->addFlash('warning', 'Configuration saved, but the database was not updated. Run php bin/console app:analytics:glossary:sync after correcting the database issue.');
        }

        return $this->back($request);
    }

    #[Route('/dashboard/data-model/glossary/sync', name: 'app_bi_glossary_sync', methods: ['POST'])]
    public function synchronize(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        if (!$this->validToken($request)) {
            return $this->back($request);
        }
        try {
            $this->sync->sync();
            $this->addFlash('success', 'BI glossary synced.');
        } catch (GlossaryValidationException $error) {
            return $this->page($request, $error->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $error) {
            $this->logFailure('sync', $error);
            $this->addFlash('error', 'The glossary could not be synced. Check database connectivity, migrations, and permissions.');
        }

        return $this->back($request);
    }

    #[Route('/dashboard/data-model/glossary/suggestions', name: 'app_bi_glossary_suggestions', methods: ['POST'])]
    public function discover(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        if (!$this->validToken($request)) {
            return $this->back($request);
        }
        $names = [];
        $errors = [];
        try {
            $settings = $this->settings->get();
            $names = $this->suggestions->find(['view', ...array_keys($settings['values']['event_name'] ?? [])]);
        } catch (GlossaryValidationException $error) {
            $errors = $error->errors();
        } catch (\Throwable $error) {
            $this->logFailure('suggestions', $error);
            $errors['bi_glossary.suggestions'] = 'Event-name suggestions could not be loaded. Check database connectivity and permissions.';
        }

        return $this->page($request, $errors, suggestions: $names);
    }

    #[Route('/dashboard/data-model/glossary/download', name: 'app_bi_glossary_download', methods: ['GET'])]
    public function download(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        return $this->downloadResponse(Yaml::dump(['bi_glossary' => $this->settings->get()], 10, 2), 'bi-glossary.yaml', 'application/yaml');
    }

    #[Route('/dashboard/data-model/glossary/missing', name: 'app_bi_glossary_missing', methods: ['GET'])]
    public function missing(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        return $this->downloadResponse(GlossarySync::csv($this->resolver->resolve(), true), 'bi-glossary-missing.csv', 'text/csv');
    }

    private function settingsFromForm(array $form): array
    {
        $allowed = ['_csrf_token', 'action', 'locale', 'default_locale', 'locales', 'entry_type', 'subject', 'code', 'label', 'group', 'description', 'sort'];
        if (array_diff(array_keys($form), $allowed) !== [] || array_filter($form, static fn (mixed $value): bool => !is_string($value)) !== []) {
            throw new \InvalidArgumentException('Invalid glossary form. Nothing was saved.');
        }
        $settings = $this->settings->get();
        if (($form['action'] ?? null) === 'locales') {
            $settings['default_locale'] = $form['default_locale'] ?? '';
            $settings['locales'] = preg_split('/[\s,]+/', trim($form['locales'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);

            return $settings;
        }
        if (!in_array($form['action'] ?? null, ['entry', 'reset'], true)
            || !in_array($form['entry_type'] ?? null, ['value', 'column'], true)
            || !in_array($form['locale'] ?? null, $settings['locales'], true)) {
            throw new \InvalidArgumentException('Choose a glossary entry and a published locale. Nothing was saved.');
        }
        $type = $form['entry_type'] === 'value' ? 'values' : 'columns';
        $subject = $form['subject'] ?? '';
        $code = trim($form['code'] ?? '');
        $locale = $form['locale'];
        if ($form['action'] === 'reset') {
            unset($settings[$type][$subject][$code]);
            if (($settings[$type][$subject] ?? []) === []) {
                unset($settings[$type][$subject]);
            }

            return $settings;
        }
        $entry = $settings[$type][$subject][$code] ?? [];
        foreach (['label', 'group', 'description'] as $field) {
            if ($field === 'group' && $type === 'columns') {
                continue;
            }
            if ($field === 'description' && $type === 'columns'
                && $locale === $settings['default_locale'] && $this->propertyForColumn($subject, $code) !== null) {
                continue;
            }
            $texts = $entry[$field] ?? [];
            if (is_string($texts)) {
                $texts = [$settings['default_locale'] => $texts];
            }
            $value = $form[$field] ?? '';
            if (trim($value) === '') {
                unset($texts[$locale]);
            } else {
                $texts[$locale] = $value;
            }
            if ($texts === []) {
                unset($entry[$field]);
            } else {
                $entry[$field] = $texts;
            }
        }
        $sort = trim($form['sort'] ?? '');
        if ($sort === '') {
            unset($entry['sort']);
        } else {
            // Keep invalid input as-is so the shared validator identifies its field.
            $entry['sort'] = preg_match('/^\d{1,6}$/D', $sort) === 1 ? (int) $sort : $sort;
        }
        $settings[$type][$subject][$code] = $entry;

        return $settings;
    }

    private function page(Request $request, array $errors = [], int $status = 200, ?array $suggestions = null): Response
    {
        $settings = ['default_locale' => 'en', 'locales' => ['en'], 'values' => [], 'columns' => []];
        $rows = [];
        $syncStatus = null;
        $statusError = null;
        $loaded = false;
        try {
            $settings = $this->settings->get();
            $rows = $this->resolver->resolve($settings);
            $loaded = true;
        } catch (GlossaryValidationException $error) {
            $errors += $error->errors();
        } catch (\Throwable $error) {
            $this->logFailure('load', $error);
            $errors['bi_glossary'] = 'The glossary configuration could not be loaded. Correct its YAML before saving.';
        }
        if ($loaded) {
            try {
                $syncStatus = !$this->sync->diff($rows)['changed'];
            } catch (\Throwable $error) {
                $this->logFailure('status', $error);
                $statusError = 'Sync status is unavailable. Check database connectivity, migrations, and permissions.';
            }
        }
        $form = $request->request->all();
        $locale = $form['locale'] ?? $request->query->all()['locale'] ?? $settings['default_locale'];
        if (!is_string($locale) || !in_array($locale, $settings['locales'], true)) {
            $locale = $settings['default_locale'];
        }
        $entries = ['values' => [], 'columns' => []];
        $resolved = [];
        foreach ($rows as $row) {
            $type = $row['entry_type'] === 'value' ? 'values' : 'columns';
            $resolved[$type][$row['subject']][$row['code']][$row['locale']] = $row;
        }
        if ($loaded) {
            foreach ($this->settings->dimensions() as $dimension => $codes) {
                $entries['values'][$dimension] = [];
            }
            $addition = $request->query->all();
            if (isset($addition['add_dimension'], $addition['add_code'])) {
                try {
                    if (!is_string($addition['add_dimension']) || !is_string($addition['add_code'])
                        || !$this->allowsNewCode($addition['add_dimension'])) {
                        throw new \InvalidArgumentException('Choose an event-name or custom-property dimension.');
                    }
                    $preview = $settings;
                    $preview['values'][$addition['add_dimension']][$addition['add_code']] ??= [];
                    $this->settings->validate($preview);
                    $resolved['values'][$addition['add_dimension']][$addition['add_code']] ??= [];
                } catch (GlossaryValidationException $error) {
                    $errors += $error->errors();
                } catch (\InvalidArgumentException $error) {
                    $errors['bi_glossary.values'] = $error->getMessage();
                }
            }
            // Keep an unsaved declared-code row editable after validation fails.
            if ($errors !== [] && ($form['entry_type'] ?? null) === 'value'
                && is_string($form['subject'] ?? null) && array_key_exists($form['subject'], $entries['values'])
                && is_string($form['code'] ?? null) && strlen($form['code']) <= 191) {
                $resolved['values'][$form['subject']][$form['code']] ??= [];
            }
        }
        foreach ($resolved as $type => $subjects) {
            foreach ($subjects as $subject => $codes) {
                foreach ($codes as $code => $translations) {
                    $current = $translations[$locale] ?? ['label' => (string) $code, 'label_locale' => null, 'description' => null, 'group_label' => null, 'sort_order' => 0];
                    $default = $translations[$settings['default_locale']] ?? $current;
                    $entry = $settings[$type][$subject][$code] ?? [];
                    $input = [];
                    foreach (['label', 'group', 'description'] as $field) {
                        $text = $entry[$field] ?? [];
                        $input[$field] = is_array($text) ? ($text[$locale] ?? '') : ($locale === $settings['default_locale'] ? $text : '');
                    }
                    $input['sort'] = $entry['sort'] ?? '';
                    $isSubmitted = $errors !== [] && ($form['entry_type'] ?? null) === ($type === 'values' ? 'value' : 'column')
                        && ($form['subject'] ?? null) === $subject && ($form['code'] ?? null) === (string) $code;
                    if ($isSubmitted) {
                        foreach (array_keys($input) as $field) {
                            $input[$field] = is_string($form[$field] ?? null) ? $form[$field] : '';
                        }
                    }
                    $property = $type === 'columns' ? $this->propertyForColumn($subject, (string) $code) : null;
                    $entries[$type][$subject][] = [
                        'code' => (string) $code, 'current' => $current, 'default' => $default, 'input' => $input,
                        'source' => match ($default['source'] ?? 'builtin') { 'config' => $type === 'values' ? 'goals.yaml' : 'Data model', 'glossary' => 'Glossary', default => 'Built-in' },
                        'property' => $property, 'overridden' => isset($settings[$type][$subject][$code]),
                        'unsaved' => $translations === [], 'open' => $isSubmitted || $translations === [],
                    ];
                }
            }
        }
        $consent = [];
        if ($loaded) {
            foreach ($this->settings->properties() as $property) {
                if (($property['consent_required'] ?? true) && ($property['column'] ?? '') !== '') {
                    $consent[$property['column']] = true;
                }
            }
        }

        return $this->privateResponse($this->render('data_model/glossary.html.twig', [
            'settings' => $settings, 'locale' => $locale, 'entries' => $entries, 'errors' => $errors,
            'loaded' => $loaded, 'in_sync' => $syncStatus, 'status_error' => $statusError,
            'consent_dimensions' => $consent, 'suggestions' => $suggestions,
            'locale_form' => [
                'default_locale' => $errors !== [] && ($form['action'] ?? null) === 'locales' && is_string($form['default_locale'] ?? null) ? $form['default_locale'] : $settings['default_locale'],
                'locales' => $errors !== [] && ($form['action'] ?? null) === 'locales' && is_string($form['locales'] ?? null) ? $form['locales'] : implode(', ', $settings['locales']),
            ],
            'editable_dimensions' => $loaded ? array_values(array_filter(array_keys($this->settings->dimensions()), $this->allowsNewCode(...))) : [],
        ], new Response(status: $status)));
    }

    private function propertyForColumn(string $view, string $column): ?array
    {
        if (!str_starts_with($view, 'analytics_custom_')) {
            return null;
        }
        foreach ($this->settings->properties() as $key => $property) {
            if (($property['column'] ?? '') === $column || ($property['numeric_column'] ?? '') === $column) {
                return ['key' => $key, ...$property];
            }
        }

        return null;
    }

    private function allowsNewCode(string $dimension): bool
    {
        return $dimension === 'event_name' || !in_array($dimension, ['goal_event', 'referrer_channel', 'device_class', 'viewport_bucket', 'geo_area', 'geo_level', 'privacy_mode'], true);
    }

    private function validToken(Request $request): bool
    {
        $token = $request->request->all()['_csrf_token'] ?? null;
        if (is_string($token) && $this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            return true;
        }
        $this->addFlash('error', 'Invalid security token. Please try again.');

        return false;
    }

    private function back(Request $request): Response
    {
        $locale = $request->request->all()['locale'] ?? null;

        return $this->privateResponse($this->redirectToRoute('app_bi_glossary', is_string($locale) ? ['locale' => $locale] : []));
    }

    private function downloadResponse(string $body, string $filename, string $mime): Response
    {
        return $this->privateResponse(new Response($body, headers: [
            'Content-Type' => $mime.'; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function logFailure(string $operation, \Throwable $error): void
    {
        $this->logger->error('BI glossary operation failed.', ['operation' => $operation, 'exception_class' => $error::class]);
    }

    private function denyUnlessAvailableToAdmin(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }
}
