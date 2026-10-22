<?php

namespace App\Controller;

use App\EventSubscriber\AdminAuditSubscriber;
use App\Service\AggregateConfigLoader;
use App\Service\DropInScripts;
use App\Service\TrackerNamespace;
use App\Service\TrackingAttributes;
use App\Service\WebsiteActivityService;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteDomainPolicy;
use App\Service\WebsiteScopeCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/** The Websites page: registered websites, their install snippets, domain rules and data reception status. */
class WebsitesController extends AbstractController
{
    use DashboardAccess;

    public function __construct(
        private readonly WebsiteConfigManager $websiteManager,
        private readonly AggregateConfigLoader $config,
        private readonly LoggerInterface $logger,
        private readonly WebsiteDomainPolicy $websiteDomainPolicy = new WebsiteDomainPolicy(),
        private readonly ?WebsiteScopeCatalog $scopes = null,
    ) {}

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(Request $request, DropInScripts $scripts, ?WebsiteActivityService $activityService = null): Response
    {
        $this->denyUnlessPermitted();
        $query = $request->query->all();
        $format = ($query['format'] ?? null) === 'query' ? 'query' : 'window';
        $withTags = ($query['tags'] ?? null) === '1';

        $defaultCmp = true;
        if ($this->config->has('consent_manager')) {
            $cm = $this->config->get('consent_manager');
            if (is_array($cm) && array_key_exists('enabled', $cm) && is_bool($cm['enabled'])) {
                $defaultCmp = $cm['enabled'];
            }
        }
        $cmpParam = $query['cmp'] ?? null;
        if ($cmpParam === '0' || $cmpParam === 'false' || ($query['consent_option'] ?? null) === 'external') {
            $includeCmp = false;
        } elseif ($cmpParam === '1' || $cmpParam === 'true' || ($query['consent_option'] ?? null) === 'builtin') {
            $includeCmp = true;
        } else {
            $includeCmp = $defaultCmp;
        }
        $consentOption = $includeCmp ? 'builtin' : 'external';

        return $this->renderDashboardPage('websites/index.html.twig', [
            'websites' => array_map(function (array $website) use ($scripts, $format, $withTags, $consentOption, $activityService): array {
                try {
                    $website['domain_settings'] = $this->websiteDomainPolicy->resolve($website);
                    $website['domain_settings_invalid'] = false;
                } catch (\InvalidArgumentException) {
                    $website['domain_settings'] = ['mode' => 'restricted', 'domains' => []];
                    $website['domain_settings_invalid'] = true;
                }
                $website['integration_code'] = null;
                $website['tracker_url'] = null;
                try {
                    $website['integration_code'] = $scripts->snippet($website['token'], $withTags, $format, $consentOption);
                    if ($withTags) {
                        $website['tracker_url'] = $scripts->trackerUrl($website['token']);
                    }
                } catch (\Throwable) {
                    // A broken site's script settings must not hide other sites
                    // or prevent the operator from repairing its domain rules.
                    $website['integration_code'] = null;
                }
                // Only a status already computed: the page never waits on the
                // events database. The page loads fresh ones from app_website_activity.
                $website['activity'] = $activityService?->cachedStatusForToken((string) ($website['token'] ?? ''));
                $website['id'] = WebsiteConfigManager::idForToken((string) ($website['token'] ?? ''));
                $website['group_id'] = ($this->scopes !== null && !$this->scopes->hasLoadError())
                    ? $this->scopes->groupIdForToken((string) ($website['token'] ?? ''))
                    : null;

                return $website;
            }, $this->visibleWebsites($this->websiteManager->getWebsites())),
            'snippet_format' => $format,
            'include_tags' => $withTags,
            'include_cmp' => $includeCmp,
            'js_namespace' => TrackerNamespace::of($this->config),
            'tracking_attributes' => TrackingAttributes::names(TrackerNamespace::of($this->config)),
        ]);
    }

    /**
     * Each website's data reception status, which the Websites page loads after
     * it renders. The badge and banner are rendered here from the same Twig
     * partials the page uses, so the browser only swaps them in.
     */
    #[Route('/dashboard/websites/activity', name: 'app_website_activity', methods: ['GET'])]
    public function websiteActivity(WebsiteActivityService $activityService): JsonResponse
    {
        $this->denyUnlessPermitted();
        $headers = ['Cache-Control' => 'private, no-store, max-age=0'];

        try {
            $statuses = $activityService->getStatuses();
        } catch (\Throwable $error) {
            $this->logger->warning('Website activity could not be read from the events table.', ['exception' => $error]);

            return new JsonResponse(['error' => 'Data reception status is unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE, $headers);
        }

        $websites = [];
        foreach ($this->visibleWebsites($this->websiteManager->getWebsites()) as $website) {
            $token = (string) ($website['token'] ?? '');
            if ($token === '') {
                continue;
            }
            $activity = $statuses[$token] ?? $activityService->getStatusForToken($token);
            $websites[$token] = [
                'status' => $activity['status'],
                'badge' => $this->renderView('components/Websites/_activity_badge.html.twig', ['activity' => $activity, 'token' => $token]),
                'banner' => $this->renderView('components/Websites/_activity_banner.html.twig', ['activity' => $activity, 'token' => $token, 'website' => $website]),
            ];
        }

        return new JsonResponse(['websites' => $websites], Response::HTTP_OK, $headers);
    }

    #[Route('/dashboard/website/create', name: 'app_website_create', methods: ['POST'])]
    public function createWebsite(Request $request): Response
    {
        $this->denyUnlessPermitted();

        if (!$this->isCsrfTokenValid('create_website', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        $values = $request->request->all();
        $name = is_string($values['name'] ?? null) ? trim($values['name']) : '';
        $domain = is_string($values['domain'] ?? null) ? trim($values['domain']) : '';

        // Validate
        if (empty($name)) {
            $this->addFlash('error', 'Website name is required');
            return $this->redirectToRoute('app_dashboard');
        }

        if (empty($domain)) {
            $this->addFlash('error', 'Domain is required');
            return $this->redirectToRoute('app_dashboard');
        }

        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $name);
        try {
            $domain = $this->websiteDomainPolicy->normalizePrimaryDomain($domain);
            $policy = $this->submittedDomainPolicy($request, $domain);
            $success = $this->websiteManager->addWebsite($name, $domain, domainPolicy: $policy);

            if ($success) {
                $this->addFlash('success', "Website '{$name}' created successfully!");
            } else {
                $this->addFlash('error', 'Failed to save to config/websites.yaml');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create website: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/website/{token}/domains', name: 'app_website_domains', methods: ['POST'])]
    public function saveWebsiteDomains(string $token, Request $request): Response
    {
        $this->denyUnlessPermitted();
        if (!$this->canUseWebsiteToken($token)) {
            throw $this->createNotFoundException('Website not found.');
        }

        if (!$this->isCsrfTokenValid('website_domains_'.$token, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        $this->nameWebsiteForAudit($request, $token);
        try {
            $policy = $this->submittedDomainPolicy($request);
            if ($policy === null) {
                throw new \InvalidArgumentException('Choose a domain restriction mode.');
            }
            if ($this->websiteManager->updateDomainPolicy($token, $policy)) {
                $this->addFlash('success', 'Website domain rules saved. The existing website token is unchanged.');
            } else {
                $this->addFlash('error', 'Website not found or config/websites.yaml could not be saved.');
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Exception) {
            $this->addFlash('error', 'Could not save website domain rules. Check config/websites.yaml and its permissions.');
        }

        return $this->redirectToRoute('app_dashboard');
    }

    /** The audit trail names the website, never its token. */
    private function nameWebsiteForAudit(Request $request, string $token): void
    {
        try {
            $name = $this->websiteManager->findOneByToken($token)['name'] ?? null;
        } catch (\Throwable) {
            $name = null;
        }
        if (is_string($name) && $name !== '') {
            $request->attributes->set(AdminAuditSubscriber::SUBJECT, $name);
        }
    }

    private function submittedDomainPolicy(Request $request, ?string $newWebsiteDomain = null): ?array
    {
        $values = $request->request->all();
        // Preserve old clients that create registrations without the new fields.
        if (!array_key_exists('domain_mode', $values) && !array_key_exists('allowed_domains', $values)) {
            return null;
        }
        $mode = $values['domain_mode'] ?? null;
        $text = $values['allowed_domains'] ?? '';
        if (!is_string($mode) || !is_string($text) || strlen($text) > 16_384) {
            throw new \InvalidArgumentException('Choose a domain restriction mode and enter at most 32 domains, one per line.');
        }
        $domains = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []),
            static fn (string $domain): bool => $domain !== '',
        ));
        if ($mode === 'restricted' && $domains === [] && $newWebsiteDomain !== null) {
            $domains = [$newWebsiteDomain];
        }

        return $this->websiteDomainPolicy->normalize(['mode' => $mode, 'domains' => $domains]);
    }

    #[Route('/dashboard/website/delete/{token}', name: 'app_website_delete', methods: ['POST'])]
    public function deleteWebsite(string $token, Request $request): Response
    {
        $this->denyUnlessPermitted();
        if (!$this->canUseWebsiteToken($token)) {
            throw $this->createNotFoundException('Website not found.');
        }

        if (!$this->isCsrfTokenValid('delete_website_'.$token, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        $this->nameWebsiteForAudit($request, $token);
        try {
            $success = $this->websiteManager->removeWebsite($token);

            if ($success) {
                try {
                    $this->scopes?->forgetWebsite($token);
                } catch (\Throwable $error) {
                    $this->logger->warning('A deleted website could not be removed from its group.', ['exception' => $error]);
                    $this->addFlash('warning', 'The website was deleted, but its group membership could not be updated. Correct config/website-scopes.yaml.');
                }
                $this->addFlash('success', 'Website deleted successfully!');
            } else {
                $this->addFlash('error', 'Website not found or could not be deleted.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to delete website: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
