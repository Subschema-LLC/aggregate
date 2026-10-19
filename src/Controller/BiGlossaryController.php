<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\Glossary\BiGlossarySettings;
use App\Service\Glossary\EventNameSuggestions;
use App\Service\Glossary\GlossaryForm;
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
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'bi_glossary';

    private readonly GlossaryForm $form;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly BiGlossarySettings $settings,
        private readonly GlossaryResolver $resolver,
        private readonly GlossarySync $sync,
        private readonly EventNameSuggestions $suggestions,
        private readonly LoggerInterface $logger,
    ) {
        $this->form = new GlossaryForm($settings);
    }

    #[Route('/dashboard/data-model/glossary', name: 'app_bi_glossary', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessPermitted();

        return $this->page($request);
    }

    #[Route('/dashboard/data-model/glossary/save', name: 'app_bi_glossary_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessPermitted();
        if (!$this->validToken($request)) {
            return $this->back($request);
        }
        try {
            $this->settings->save($this->form->apply($request->request->all()));
        } catch (GlossaryValidationException $error) {
            return $this->page($request, $error->errors(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\InvalidArgumentException $error) {
            return $this->page($request, ['bi_glossary' => $error->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $error) {
            $this->logFailure('save', $error);
            $this->addFlash('error', 'The glossary could not be saved. Check the active YAML configuration and file permissions.');
            if ($request->hasSession()) {
                $request->getSession()->set('bi_glossary_submitted_form', $request->request->all());
            }

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
        $this->denyUnlessPermitted();
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
        $this->denyUnlessPermitted();
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
        $this->denyUnlessPermitted();

        return $this->downloadResponse(Yaml::dump(['bi_glossary' => $this->settings->get()], 10, 2), 'bi-glossary.yaml', 'application/yaml');
    }

    #[Route('/dashboard/data-model/glossary/missing', name: 'app_bi_glossary_missing', methods: ['GET'])]
    public function missing(): Response
    {
        $this->denyUnlessPermitted();

        return $this->downloadResponse(GlossarySync::csv($this->resolver->resolve(), true), 'bi-glossary-missing.csv', 'text/csv');
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
        if ($form === [] && $request->hasSession() && $request->getSession()->has('bi_glossary_submitted_form')) {
            $sessionForm = $request->getSession()->remove('bi_glossary_submitted_form');
            if (is_array($sessionForm)) {
                $form = $sessionForm;
            }
        }
        $locale = $form['locale'] ?? $request->query->all()['locale'] ?? $settings['default_locale'];
        if (!is_string($locale) || !in_array($locale, $settings['locales'], true)) {
            $locale = $settings['default_locale'];
        }
        $resolved = GlossaryForm::grouped($rows);
        if ($loaded) {
            $addition = $request->query->all();
            if (isset($addition['add_dimension'], $addition['add_code'])) {
                try {
                    if (!is_string($addition['add_dimension']) || !is_string($addition['add_code'])
                        || !GlossaryForm::allowsNewCode($addition['add_dimension'])) {
                        throw new \InvalidArgumentException('Choose an event-name, website or custom-property dimension.');
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
        }
        $dimensions = $loaded ? array_keys($this->settings->dimensions()) : [];
        $entries = $this->form->entries($resolved, $settings, $locale, $dimensions, $errors !== [] ? $form : null);
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
            'editable_dimensions' => array_values(array_filter($dimensions, GlossaryForm::allowsNewCode(...))),
        ], new Response(status: $status)));
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
}
