<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\DropInScripts;
use App\Service\SiteScriptConfig;
use App\Service\TagManagerSettings;
use App\Service\TagManagerVariables;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Yaml\Yaml;

final class TagManagerController extends AbstractController
{
    public const CSRF_TOKEN_ID = 'tag_manager_settings';
    /** Session key for a form that failed validation, so the next page load can show it again. */
    public const SUBMITTED_FORM_KEY = 'tag_manager_submitted_form';
    /** Suggested ID when the page adds the website's tracker as a tag. */
    public const TRACKER_TAG_ID = 'aggregate-tracker';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly TagManagerSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly SiteScriptConfig $sites,
        private readonly ?DropInScripts $scripts = null,
    ) {
    }

    #[Route('/dashboard/tag-manager', name: 'app_tag_manager', methods: ['GET'])]
    public function index(?Request $request = null): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $websites = $this->sites->sites();
        $query = $request?->query->all() ?? [];
        $siteId = array_key_exists('site', $query) ? $query['site'] : ($websites[0]['id'] ?? '');
        try {
            $site = $this->selectedSite($siteId);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Choose a registered website or shared configuration.');
        }
        $settings = TagManagerSettings::DEFAULTS;
        $consent = null;
        $configurationError = null;
        try {
            $settings = $this->settings->all($siteId !== '' ? $siteId : null);
            $consent = $siteId !== '' ? $this->sites->consent($siteId) : null;
        } catch (\Throwable $e) {
            $configurationError = 'Script configuration could not be loaded. Correct its YAML before saving. Invalid configuration is not served to visitors.';
            $this->logFailure('load', $e);
        }
        $variableRows = [];
        foreach ($settings['variables'] as $alias => $path) {
            $variableRows[] = ['alias' => $alias, 'path' => $path];
        }

        $submitted = $this->takeSubmittedForm($request, $siteId);
        $restored = $submitted !== null && $configurationError === null;
        if ($restored) {
            [$settings, $variableRows] = $this->draftFromForm($submitted, $settings, $variableRows);
        }

        $trackerUrl = $this->trackerUrl($site);
        $trackerTag = null;
        foreach ($settings['tags'] as $tag) {
            if ($trackerUrl !== null && ($tag['src'] ?? null) === $trackerUrl && ($tag['type'] ?? 'script') === 'script') {
                $trackerTag = $tag['id'];
                break;
            }
        }

        return $this->privateResponse($this->render('tag_manager/index.html.twig', [
            'settings' => $settings, 'variable_rows' => $variableRows, 'consent_settings' => $consent,
            'sites' => $websites, 'selected_site' => $siteId, 'site' => $site,
            'max_tags' => TagManagerSettings::MAX_TAGS, 'max_variables' => TagManagerVariables::MAX_VARIABLES,
            'configuration_error' => $configurationError, 'restored_form' => $restored,
            'tracker_url' => $trackerUrl, 'tracker_tag' => $trackerTag, 'tracker_tag_id' => self::TRACKER_TAG_ID,
        ]));
    }

    #[Route('/dashboard/tag-manager/save', name: 'app_tag_manager_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $submitted = $request->request->all();
        $siteId = is_string($submitted['site'] ?? null) ? $submitted['site'] : '';
        $csrf = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrf) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $csrf)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->back($siteId);
        }

        try {
            $this->selectedSite(array_key_exists('site', $submitted) ? $submitted['site'] : '');
            $tagSettings = $this->settingsFromForm($submitted);
            // Consent manager settings have their own page and save separately.
            $this->settings->save($tagSettings, $siteId !== '' ? $siteId : null);
            $this->addFlash('success', 'Script settings saved to YAML. Changes apply on the next page load.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            $this->rememberSubmittedForm($request, $siteId, $submitted);
        } catch (\Throwable $e) {
            $this->logFailure('save', $e);
            $this->addFlash('error', 'Script settings could not be saved. Check the YAML configuration and file permissions.');
            $this->rememberSubmittedForm($request, $siteId, $submitted);
        }

        return $this->back($siteId);
    }

    /**
     * Keeps a rejected form for one page load. Only forms that passed the CSRF
     * check are kept, so another site cannot prefill an administrator's editor.
     */
    private function rememberSubmittedForm(Request $request, string $siteId, array $submitted): void
    {
        if (!$request->hasSession()) {
            return;
        }
        unset($submitted['_csrf_token']);
        $request->getSession()->set(self::SUBMITTED_FORM_KEY, ['site' => $siteId, 'form' => $submitted]);
    }

    /** The rejected form for this website, removed from the session whether or not it matches. */
    private function takeSubmittedForm(?Request $request, string $siteId): ?array
    {
        if ($request === null || !$request->hasSession() || !$request->getSession()->has(self::SUBMITTED_FORM_KEY)) {
            return null;
        }
        $stored = $request->getSession()->remove(self::SUBMITTED_FORM_KEY);
        if (!is_array($stored) || ($stored['site'] ?? null) !== $siteId || !is_array($stored['form'] ?? null)) {
            return null;
        }

        return $stored['form'];
    }

    /**
     * Rebuilds the editor rows from a rejected form exactly as typed, including
     * values that failed validation. Nothing here is saved or served.
     *
     * @return array{0: array, 1: list<array>}
     */
    private function draftFromForm(array $form, array $settings, array $variableRows): array
    {
        $text = static fn (mixed $value, int $limit = 4096): string => is_string($value) ? substr($value, 0, $limit) : '';
        $rows = static fn (mixed $value, int $limit): array => is_array($value) ? array_slice(array_values($value), 0, $limit) : [];

        if (in_array($form['enabled'] ?? null, ['0', '1'], true)) {
            $settings['enabled'] = $form['enabled'] === '1';
        }

        if (array_key_exists('variables', $form)) {
            $variableRows = [];
            foreach ($rows($form['variables'], TagManagerVariables::MAX_VARIABLES) as $row) {
                if (!is_array($row)) continue;
                $variable = ['alias' => $text($row['alias'] ?? null), 'path' => $text($row['path'] ?? null), 'remove' => ($row['remove'] ?? null) === '1'];
                if ($variable['alias'] !== '' || $variable['path'] !== '') $variableRows[] = $variable;
            }
        }

        if (array_key_exists('tags', $form)) {
            $tags = [];
            foreach ($rows($form['tags'], TagManagerSettings::MAX_TAGS) as $row) {
                if (!is_array($row)) continue;
                $trigger = is_array($row['trigger'] ?? null) ? $row['trigger'] : [];
                $tag = [
                    'id' => $text($row['id'] ?? null),
                    'type' => in_array($row['type'] ?? null, ['script', 'call'], true) ? $row['type'] : 'script',
                    'src' => $text($row['src'] ?? null),
                    'method' => $text($row['method'] ?? null),
                    'args_json' => $text($row['args_json'] ?? null, 2_000_000),
                    'enabled' => ($row['enabled'] ?? '1') !== '0',
                    'consent' => $text($row['consent'] ?? null, 64),
                    'trigger' => ['type' => $text($trigger['type'] ?? null, 64) ?: 'dom_ready', 'event' => $text($trigger['event'] ?? null, 200)],
                    'remove' => ($row['remove'] ?? null) === '1',
                ];
                // The trailing empty row comes back as the page's usual new-tag row.
                if ($tag['id'] === '' && $tag['src'] === '' && $tag['method'] === '' && in_array(trim($tag['args_json']), ['', '[]'], true)) continue;
                $tags[] = $tag;
            }
            $settings['tags'] = $tags;
        }

        return [$settings, $variableRows];
    }

    /** The selected website's configured tracker URL, ready to paste as a script tag. */
    private function trackerUrl(?array $site): ?string
    {
        if ($site === null || $this->scripts === null) {
            return null;
        }
        try {
            return $this->scripts->trackerUrl($site['token']);
        } catch (\Throwable $e) {
            // A missing or invalid app_host only hides the suggestion; the editor still works.
            $this->logFailure('tracker_url', $e);

            return null;
        }
    }

    #[Route('/dashboard/tag-manager/download', name: 'app_tag_manager_download', methods: ['GET'])]
    public function download(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $query = $request->query->all();
        $siteId = array_key_exists('site', $query) ? $query['site'] : '';
        try {
            $this->selectedSite($siteId);
            $content = $siteId !== '' ? $this->sites->export($siteId)
                : Yaml::dump(['tag_manager' => $this->settings->all()], 12, 2);
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Choose a registered website with valid script settings.', Response::HTTP_BAD_REQUEST));
        } catch (\Throwable $e) {
            $this->logFailure('download', $e);

            return $this->privateResponse(new Response('Script settings are unavailable. Check the YAML configuration.', Response::HTTP_SERVICE_UNAVAILABLE));
        }
        $filename = $siteId !== '' ? 'website-scripts-'.$siteId.'.yaml' : 'tag-manager.yaml';

        return $this->privateResponse(new Response($content, headers: [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]));
    }

    private function settingsFromForm(array $submitted): array
    {
        if (array_diff(array_keys($submitted), ['_csrf_token', 'site', 'enabled', 'variables', 'tags']) !== []
            || !in_array($submitted['enabled'] ?? null, ['0', '1'], true)
            || !is_array($submitted['tags'] ?? null) || !array_is_list($submitted['tags'])
            || count($submitted['tags']) > TagManagerSettings::MAX_TAGS) {
            throw new \InvalidArgumentException('The tag manager form contained missing or unexpected settings. Nothing was saved.');
        }
        $rows = $submitted['variables'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > TagManagerVariables::MAX_VARIABLES) {
            throw new \InvalidArgumentException('Variables must be a list of aliases and data paths.');
        }
        $variables = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['alias', 'path', 'remove']) !== []
                || !is_string($row['alias'] ?? null) || !is_string($row['path'] ?? null)
                || (array_key_exists('remove', $row) && $row['remove'] !== '1')) {
                throw new \InvalidArgumentException('Each variable needs an alias and a data path.');
            }
            if (($row['remove'] ?? null) === '1' || ($row['alias'] === '' && $row['path'] === '')) continue;
            if (array_key_exists($row['alias'], $variables)) {
                throw new \InvalidArgumentException('Each variable alias must be unique.');
            }
            $variables[$row['alias']] = $row['path'];
        }

        $tags = [];
        foreach ($submitted['tags'] as $tag) {
            if (!is_array($tag) || array_diff(array_keys($tag), ['id', 'type', 'src', 'method', 'args_json', 'enabled', 'consent', 'trigger', 'remove']) !== []
                || !is_string($tag['id'] ?? null) || !in_array($tag['enabled'] ?? null, ['0', '1'], true)
                || (array_key_exists('remove', $tag) && $tag['remove'] !== '1')) {
                throw new \InvalidArgumentException('Each tag needs a valid ID, action and availability setting.');
            }
            foreach (['type', 'src', 'method', 'args_json', 'consent'] as $key) {
                if (array_key_exists($key, $tag) && !is_string($tag[$key])) {
                    throw new \InvalidArgumentException('Tag action and consent fields must be text.');
                }
            }
            if (($tag['remove'] ?? null) === '1') continue;
            $type = $tag['type'] ?? 'script';
            $src = $tag['src'] ?? '';
            $method = $tag['method'] ?? '';
            $args = trim($tag['args_json'] ?? '');
            if ($tag['id'] === '' && $src === '' && $method === '' && in_array($args, ['', '[]'], true)) continue;
            if ($type === 'script') {
                if ($method !== '' || !in_array($args, ['', '[]'], true)) {
                    throw new \InvalidArgumentException('A script action uses its HTTPS URL. Clear the method and arguments or choose Call a library method.');
                }
                $action = ['src' => $src];
            } elseif ($type === 'call') {
                if ($src !== '') throw new \InvalidArgumentException('A method action must not also contain a script URL.');
                try {
                    if (strlen($args) > 2_000_000 || ($args !== '' && !str_starts_with($args, '['))) {
                        throw new \InvalidArgumentException('Method arguments must be a JSON array.');
                    }
                    $decoded = $args === '' ? [] : json_decode($args, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new \InvalidArgumentException('Method arguments must be valid JSON, for example ["purchase", {"total_minor": 1299}].');
                }
                $action = ['method' => $method, 'args' => $decoded];
            } else {
                throw new \InvalidArgumentException('Choose a script or library method action.');
            }
            $trigger = $tag['trigger'] ?? ['type' => 'dom_ready'];
            if (!is_array($trigger) || array_diff(array_keys($trigger), ['type', 'event']) !== []
                || !is_string($trigger['type'] ?? null)
                || (array_key_exists('event', $trigger) && !is_string($trigger['event']))) {
                throw new \InvalidArgumentException('Each tag needs a valid trigger type and event name.');
            }
            if (in_array($trigger['type'], ['dom_ready', 'window_load'], true) && ($trigger['event'] ?? '') === '') unset($trigger['event']);
            $tags[] = ['id' => $tag['id'], 'type' => $type, ...$action, 'enabled' => $tag['enabled'] === '1', 'consent' => $tag['consent'] ?? 'analytics', 'trigger' => $trigger];
        }

        return TagManagerSettings::validate(['enabled' => $submitted['enabled'] === '1', 'variables' => $variables, 'tags' => $tags]);
    }

    private function selectedSite(mixed $siteId): ?array
    {
        if (!is_string($siteId)) throw new \InvalidArgumentException('Choose a registered website.');

        return $siteId === '' ? null : $this->sites->site($siteId);
    }

    private function back(string $siteId): Response
    {
        return $this->privateResponse($this->redirectToRoute('app_tag_manager', ['site' => $siteId]));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function logFailure(string $operation, \Throwable $error): void
    {
        $this->logger->error('Tag manager configuration operation failed.', ['operation' => $operation, 'exception_class' => $error::class]);
    }

    private function denyUnlessAvailableToAdmin(): void
    {
        if (!$this->config->isDashboardEnabled()) throw $this->createNotFoundException('Dashboard is disabled.');
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }
}
