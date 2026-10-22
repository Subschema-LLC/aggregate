<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\ConsentAppearance;
use App\Service\ConsentCategories;
use App\Service\ConsentManagerForm;
use App\Service\SiteScriptConfig;
use App\Service\TagManagerSettings;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Consent manager lite: the built-in banner's per-website settings. They are
 * the consent_manager mapping in config/tag-manager/sites/<site-id>.yaml, so
 * YAML and this page share one source and one validation.
 */
final class ConsentManagerController extends AbstractController
{
    use DashboardAccess;

    public const CSRF_TOKEN_ID = 'consent_manager_settings';
    /** A rejected form, kept for one page load of the same website. */
    public const SUBMITTED_FORM_KEY = 'consent_manager_submitted_form';

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly SiteScriptConfig $sites,
        private readonly TagManagerSettings $tags,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/consent-manager', name: 'app_consent_manager', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $websites = $this->visibleWebsites($this->sites->sites());
        $query = $request->query->all();
        $cookieSite = $request->cookies->get('aggregate_site');
        $fallbackSiteId = (is_string($cookieSite) && $cookieSite !== '' && $this->canUseSiteId($cookieSite))
            ? $cookieSite
            : ($websites[0]['id'] ?? null);
        $siteId = array_key_exists('site', $query) ? $query['site'] : $fallbackSiteId;
        if ($siteId !== null && (!is_string($siteId) || !$this->canUseSiteId($siteId))) {
            throw $this->createNotFoundException('Choose a website you can use.');
        }
        $site = null;
        if ($siteId !== null) {
            try {
                $site = $this->sites->site($siteId);
            } catch (\InvalidArgumentException) {
                throw $this->createNotFoundException('Choose a registered website.');
            }
        }

        $form = null;
        $categories = ['analytics'];
        $error = null;
        $restored = false;
        if ($site !== null) {
            try {
                $consent = $this->sites->consent($siteId);
                $categories = ConsentCategories::builtin($this->tags->consentCategories($siteId), $consent);
                $form = ConsentManagerForm::values($consent, $categories);
                $submitted = $this->takeSubmittedForm($request, $siteId);
                if ($submitted !== null) {
                    $form = ConsentManagerForm::fromSubmission($submitted, $form);
                    $restored = true;
                }
            } catch (\Throwable $e) {
                $error = 'This website’s consent settings could not be loaded. Correct the consent_manager and tag_manager YAML in its site file before saving. Invalid settings are not served to visitors.';
                $this->logFailure('load', $e);
            }
        }

        return $this->privateResponse($this->render('consent_manager/index.html.twig', [
            'sites' => $websites, 'site' => $site, 'selected_site' => $site['id'] ?? '',
            'form' => $form, 'categories' => $categories, 'configuration_error' => $error, 'restored_form' => $restored,
            'default_text' => ConsentAppearance::DEFAULT_TEXT[ConsentAppearance::BUILTIN],
            'default_theme' => ConsentAppearance::THEME_DEFAULTS[ConsentAppearance::BUILTIN],
            'default_buttons' => ConsentAppearance::BUTTON_DEFAULTS[ConsentAppearance::BUILTIN],
            'default_labels' => array_combine($categories, array_map(ConsentManagerForm::defaultLabel(...), $categories)),
            'arrangements' => ConsentManagerForm::arrangements(), 'button_names' => ConsentManagerForm::BUTTON_NAMES,
            'consent_types' => ConsentManagerForm::CONSENT_TYPE_LABELS, 'default_consent_type' => ConsentAppearance::DEFAULT_CONSENT_TYPE,
            'close_buttons' => ConsentManagerForm::CLOSE_BUTTON_LABELS,
            'reopen_positions' => ['bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right', 'hidden' => 'Hidden (link to it from your website)'],
            'max_details' => ConsentAppearance::MAX_DETAILS,
            'layouts' => ConsentManagerForm::LAYOUT_LABELS,
            'max_external_tags' => ConsentAppearance::MAX_EXTERNAL_TAGS,
        ]));
    }

    #[Route('/dashboard/consent-manager/save', name: 'app_consent_manager_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $submitted = $request->request->all();
        $siteId = is_string($submitted['site'] ?? null) ? $submitted['site'] : '';
        if (!$this->canUseSiteId($siteId)) {
            throw $this->createNotFoundException('Choose a website you can use.');
        }
        $csrf = $submitted['_csrf_token'] ?? null;
        if (!is_string($csrf) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $csrf)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->back($siteId);
        }

        try {
            $this->sites->site($siteId);
            $this->sites->saveConsent($siteId, ConsentManagerForm::changes($submitted, $this->sites->consent($siteId)));
            $this->addFlash('success', 'Consent manager settings saved to YAML. The hosted banner changes for visitors within five minutes; download a self-hosted copy again.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            $this->rememberSubmittedForm($request, $siteId, $submitted);
        } catch (\Throwable $e) {
            $this->logFailure('save', $e);
            $this->addFlash('error', 'Consent manager settings could not be saved. Check the website’s YAML configuration and file permissions.');
            $this->rememberSubmittedForm($request, $siteId, $submitted);
        }

        return $this->back($siteId);
    }

    private function rememberSubmittedForm(Request $request, string $siteId, array $submitted): void
    {
        // Only forms that passed the CSRF check reach here, so another site cannot prefill this page.
        if ($request->hasSession()) {
            unset($submitted['_csrf_token']);
            $request->getSession()->set(self::SUBMITTED_FORM_KEY, ['site' => $siteId, 'form' => $submitted]);
        }
    }

    private function takeSubmittedForm(Request $request, string $siteId): ?array
    {
        if (!$request->hasSession() || !$request->getSession()->has(self::SUBMITTED_FORM_KEY)) {
            return null;
        }
        $stored = $request->getSession()->remove(self::SUBMITTED_FORM_KEY);

        return is_array($stored) && ($stored['site'] ?? null) === $siteId && is_array($stored['form'] ?? null) ? $stored['form'] : null;
    }

    private function back(string $siteId): Response
    {
        return $this->privateResponse($this->redirectToRoute('app_consent_manager', $siteId !== '' ? ['site' => $siteId] : []));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function logFailure(string $operation, \Throwable $error): void
    {
        $this->logger->error('Consent manager configuration operation failed.', ['operation' => $operation, 'exception_class' => $error::class]);
    }
}
