<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\DropInScripts;
use App\Service\SiteScriptConfig;
use App\Service\TagManagerCustomCode;
use App\Service\TagManagerForm;
use App\Service\TagManagerPreview;
use App\Service\TagManagerScriptUnavailable;
use App\Service\TagManagerSettings;
use App\Service\TagManagerVariables;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Yaml\Yaml;

final class TagManagerController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'tag_manager_settings';
    /** Session key for a form that failed validation, so the next page load can show it again. */
    public const SUBMITTED_FORM_KEY = 'tag_manager_submitted_form';
    /** Suggested ID when the page adds the website's tracker as a tag. */
    public const TRACKER_TAG_ID = 'aggregate-tracker';
    /** Session key for the latest "Test this tag" run; a new run replaces it. */
    public const PREVIEW_KEY = 'tag_manager_preview';
    /** ID a new tag is tested under before it has one. */
    public const PREVIEW_TAG_ID = 'tag-being-tested';

    private readonly TagManagerPreview $preview;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly TagManagerSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly SiteScriptConfig $sites,
        private readonly ?DropInScripts $scripts = null,
        ?TagManagerPreview $preview = null,
    ) {
        $this->preview = $preview ?? new TagManagerPreview();
    }

    #[Route('/dashboard/tag-manager', name: 'app_tag_manager', methods: ['GET'])]
    public function index(?Request $request = null): Response
    {
        $this->denyUnlessPermitted();
        $websites = $this->visibleWebsites($this->sites->sites());
        $query = $request?->query->all() ?? [];
        if ($websites === [] && !$this->canUseEveryWebsite()) {
            throw $this->createAccessDeniedException('No websites are assigned to you yet. Ask an administrator for access.');
        }
        $cookieSite = $request?->cookies->get('aggregate_site');
        $fallbackSiteId = (is_string($cookieSite) && $cookieSite !== '' && $this->canUseSiteId($cookieSite))
            ? $cookieSite
            : ($websites[0]['id'] ?? '');
        $siteId = array_key_exists('site', $query) ? $query['site'] : $fallbackSiteId;
        $this->denyUnlessSiteUsable($siteId);
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
            [$settings, $variableRows] = TagManagerForm::draft($submitted, $settings, $variableRows);
        }

        $trackerUrl = $this->trackerUrl($site);
        $trackerTag = null;
        foreach ($settings['tags'] as $tag) {
            $tagSrc = $tag['src'] ?? null;
            if ($trackerUrl !== null && is_string($tagSrc) && ($tagSrc === $trackerUrl || str_starts_with($tagSrc, $trackerUrl.'&')) && ($tag['type'] ?? 'script') === 'script') {
                $trackerTag = $tag['id'];
                break;
            }
        }

        $templates = [];
        foreach (TagManagerCustomCode::templates() as $template) {
            $templates[$template['group']][] = $template;
        }

        return $this->privateResponse($this->render('tag_manager/index.html.twig', [
            'settings' => $settings, 'variable_rows' => $variableRows, 'consent_settings' => $consent,
            'sites' => $websites, 'selected_site' => $siteId, 'site' => $site,
            'can_use_shared' => $this->canUseEveryWebsite(),
            'max_tags' => TagManagerSettings::MAX_TAGS, 'max_variables' => TagManagerVariables::MAX_VARIABLES,
            'custom_scripts_enabled' => $this->settings->customScriptsEnabled(),
            'custom_templates' => $templates,
            'custom_templates_json' => json_encode($templates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
            'configuration_error' => $configurationError, 'restored_form' => $restored,
            'tracker_url' => $trackerUrl, 'tracker_tag' => $trackerTag, 'tracker_tag_id' => self::TRACKER_TAG_ID,
            'tracker_namespaces' => $this->settings->trackerNamespaces(),
        ]));
    }

    #[Route('/dashboard/tag-manager/save', name: 'app_tag_manager_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $submitted = $request->request->all();
        $siteId = is_string($submitted['site'] ?? null) ? $submitted['site'] : '';
        // A malformed site is refused below with the other invalid input.
        $raw = array_key_exists('site', $submitted) ? $submitted['site'] : '';
        if (is_string($raw)) {
            $this->denyUnlessSiteUsable($raw);
        }
        $csrf = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrf) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $csrf)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->back($siteId);
        }

        try {
            $this->selectedSite(array_key_exists('site', $submitted) ? $submitted['site'] : '');
            $tagSettings = TagManagerForm::settings($submitted);
            if (!$this->settings->customScriptsEnabled()) {
                $this->assertNoNewCustomCode($tagSettings, $siteId !== '' ? $siteId : null);
            }
            // Consent manager settings have their own page and save separately.
            $this->settings->save($tagSettings, $siteId !== '' ? $siteId : null);
            $this->addFlash('success', 'Script settings saved to YAML. Changes reach visitors within five minutes.');
            foreach ($this->settings->emitWarnings($tagSettings) as $warning) {
                $this->addFlash('warning', $warning);
            }
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
     * "Test this tag": checks one row of the page's form as saving would,
     * without saving, and prepares the script the browser's test runs. The
     * row is tested with its variables, and the tag it runs after is assumed
     * to have finished. Nothing is written but the user's own session.
     */
    #[Route('/dashboard/tag-manager/preview', name: 'app_tag_manager_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $submitted = $request->request->all();
        $raw = array_key_exists('site', $submitted) ? $submitted['site'] : '';
        if (is_string($raw)) {
            $this->denyUnlessSiteUsable($raw);
        }
        $csrf = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrf) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $csrf)) {
            return $this->previewError('Invalid security token. Reload the page and try again.', Response::HTTP_FORBIDDEN);
        }
        $index = $submitted['preview_tag'] ?? null;
        $rows = $submitted['tags'] ?? null;
        if (!is_string($index) || preg_match('/^(0|[1-9][0-9]{0,2})$/D', $index) !== 1 || !is_array($rows) || !is_array($rows[(int) $index] ?? null)) {
            return $this->previewError('Choose a tag to test.');
        }
        if (!$request->hasSession()) {
            return $this->previewError('The test needs a signed-in session.');
        }

        $row = $rows[(int) $index];
        if (TagManagerForm::isBlankRow($row)) {
            return $this->previewError('Fill in this tag before testing it: choose when it runs and what it does.');
        }
        unset($row['remove']);
        if (($row['id'] ?? '') === '') {
            $row['id'] = self::PREVIEW_TAG_ID;
        }
        $tagId = is_string($row['id'] ?? null) ? $row['id'] : '';
        $tags = [$row];
        $after = $row['after'] ?? '';
        if (is_string($after) && $after !== '' && $after !== $tagId) {
            // Only the order matters: the test replaces this tag with a stand-in that finishes at once.
            array_unshift($tags, ['id' => $after, 'type' => 'script', 'src' => TagManagerPreview::STAND_IN_HOST.'after.js', 'enabled' => '1', 'consent' => 'none', 'trigger' => ['type' => 'dom_ready']]);
        }
        $form = [
            'site' => is_string($raw) ? $raw : '',
            'enabled' => in_array($submitted['enabled'] ?? null, ['0', '1'], true) ? $submitted['enabled'] : '0',
            'variables' => $submitted['variables'] ?? [],
            'tags' => $tags,
        ];

        try {
            // As saving does: a malformed site is refused, not read as the shared configuration.
            $this->selectedSite($raw);
            $settings = TagManagerForm::settings($form);
            $plan = $this->preview->plan($settings, $tagId, $this->settings->trackerNamespace(), $this->settings->customScriptsEnabled());
        } catch (\InvalidArgumentException $e) {
            // A new tag is tested before it has an ID; its messages say "this tag".
            $message = strtr($e->getMessage(), ['Tag '.self::PREVIEW_TAG_ID.':' => 'This tag:', 'Tag '.self::PREVIEW_TAG_ID.' ' => 'This tag ', self::PREVIEW_TAG_ID => 'this tag']);

            return $this->previewError($message);
        } catch (\Throwable $e) {
            $this->logFailure('preview', $e);

            return $this->previewError('The test could not be prepared. Check the YAML configuration.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $token = bin2hex(random_bytes(16));
        // One run at a time: a new test replaces the last one.
        $request->getSession()->set(self::PREVIEW_KEY, ['token' => $token, 'config' => $plan['config'], 'custom' => $plan['custom']]);
        $categories = $plan['tag']['consent'] !== 'none' ? [$plan['tag']['consent']] : [];

        return $this->privateResponse(new JsonResponse([
            'script' => $this->generateUrl('app_tag_manager_preview_script', ['token' => $token]),
            'namespace' => $plan['config']['namespace'],
            'probeMethod' => TagManagerPreview::PROBE_METHOD,
            'methods' => array_values(array_unique(array_filter([$plan['tag']['method'], TagManagerPreview::PROBE_METHOD]))),
            'probes' => $plan['probes'],
            'tag' => $plan['tag'],
            'categories' => $categories,
            'notes' => $plan['notes'],
        ]));
    }

    /** The script for the latest test, from the user's own session. */
    #[Route('/dashboard/tag-manager/preview/{token}.js', name: 'app_tag_manager_preview_script', requirements: ['token' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function previewScript(Request $request, string $token): Response
    {
        $this->denyUnlessPermitted();
        $stored = $request->hasSession() ? $request->getSession()->get(self::PREVIEW_KEY) : null;
        if (!is_array($stored) || !is_string($stored['token'] ?? null) || !hash_equals($stored['token'], $token)
            || !is_array($stored['config'] ?? null) || !is_array($stored['custom'] ?? null)) {
            return $this->privateResponse(new Response('This test has expired. Run it again.', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }
        try {
            $content = $this->preview->script($stored['config'], array_values(array_filter($stored['custom'], 'is_string')));
        } catch (TagManagerScriptUnavailable $e) {
            return $this->privateResponse(new Response($e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }

        return $this->privateResponse(new Response($content, headers: ['Content-Type' => 'text/javascript; charset=UTF-8']));
    }

    private function previewError(string $message, int $status = Response::HTTP_UNPROCESSABLE_ENTITY): Response
    {
        return $this->privateResponse(new JsonResponse(['error' => $message], $status));
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
        $this->denyUnlessPermitted();
        $query = $request->query->all();
        $siteId = array_key_exists('site', $query) ? $query['site'] : '';
        $this->denyUnlessSiteUsable($siteId);
        try {
            $this->selectedSite($siteId);
            $content = $siteId !== '' ? $this->sites->export($siteId)
                : Yaml::dump(['tag_manager' => $this->settings->all()], 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
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

    /**
     * With custom JavaScript switched off, existing custom tags may be kept or
     * removed but not added or changed. They are not served either way.
     * $tagSettings are already validated, so code compares as it is saved.
     */
    private function assertNoNewCustomCode(array $tagSettings, ?string $siteId): void
    {
        $saved = [];
        try {
            foreach ($this->settings->all($siteId)['tags'] as $tag) {
                if ($tag['type'] === 'custom') $saved[$tag['id']] = $tag['code'];
            }
        } catch (\Throwable) {
            // Unreadable saved settings are reported by the save itself.
        }
        foreach ($tagSettings['tags'] as $tag) {
            if ($tag['type'] === 'custom' && ($saved[$tag['id']] ?? null) !== $tag['code']) {
                throw new \InvalidArgumentException('Custom JavaScript tags are turned off for this installation on the Feature flags page. Existing ones are kept but not served, and none can be added or changed.');
            }
        }
    }

    /** A website the user can use, or the shared configuration for users with every website. */
    private function denyUnlessSiteUsable(mixed $siteId): void
    {
        if (is_string($siteId) && ($siteId === '' ? $this->canUseEveryWebsite() : $this->canUseSiteId($siteId))) {
            return;
        }
        if (is_string($siteId) && $siteId === '') {
            throw $this->createAccessDeniedException('The shared configuration needs access to every website.');
        }
        throw $this->createNotFoundException('Choose a website you can use.');
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
}
