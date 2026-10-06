<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\BrandingTheme;
use App\Service\ConsentAppearance;
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
    public const CSRF_TOKEN_ID = 'consent_manager_settings';
    /** A rejected form, kept for one page load of the same website. */
    public const SUBMITTED_FORM_KEY = 'consent_manager_submitted_form';
    private const BUTTON_NAMES = ['reject' => 'Reject', 'accept' => 'Accept all', 'save' => 'Save selection'];

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
        $this->denyUnlessAvailableToAdmin();
        $websites = $this->sites->sites();
        $query = $request->query->all();
        $siteId = array_key_exists('site', $query) ? $query['site'] : ($websites[0]['id'] ?? null);
        $site = null;
        if ($siteId !== null) {
            try {
                $site = is_string($siteId) ? $this->sites->site($siteId) : throw new \InvalidArgumentException();
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
                $categories = $this->tags->consentCategories($siteId);
                $form = self::formValues($consent, $categories);
                $submitted = $this->takeSubmittedForm($request, $siteId);
                if ($submitted !== null) {
                    $form = self::formFromSubmission($submitted, $form);
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
            'default_labels' => array_combine($categories, array_map(self::defaultLabel(...), $categories)),
            'arrangements' => self::arrangements(), 'button_names' => self::BUTTON_NAMES,
            'reopen_positions' => ['bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right', 'hidden' => 'Hidden (link to it from your website)'],
            'max_details' => ConsentAppearance::MAX_DETAILS,
        ]));
    }

    #[Route('/dashboard/consent-manager/save', name: 'app_consent_manager_save', methods: ['POST'])]
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
            $this->sites->site($siteId);
            $this->sites->saveConsent($siteId, $this->changesFromForm($submitted, $this->sites->consent($siteId)));
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

    /**
     * Turns the page's fields into consent_manager changes. A value equal to
     * its default is not stored, so later default wording still applies, and
     * null removes a key that now matches its default.
     */
    private function changesFromForm(array $form, array $saved): array
    {
        if (array_diff(array_keys($form), ['_csrf_token', 'site', 'enabled', 'name', 'privacy_policy_url', 'precheck_categories', 'text', 'theme', 'buttons']) !== []
            || !in_array($form['enabled'] ?? null, ['0', '1'], true) || !is_string($form['name'] ?? null)
            || !is_string($form['privacy_policy_url'] ?? '')
            || (array_key_exists('precheck_categories', $form) && (!is_array($form['precheck_categories']) || array_filter($form['precheck_categories'], 'is_string') !== $form['precheck_categories']))
            || !is_array($form['text'] ?? []) || !is_array($form['theme'] ?? []) || !is_array($form['buttons'] ?? [])) {
            throw new \InvalidArgumentException('The consent manager form contained missing or unexpected settings. Nothing was saved.');
        }
        $defaults = ConsentAppearance::DEFAULT_TEXT[ConsentAppearance::BUILTIN];
        $submittedText = $form['text'] ?? [];
        $text = [];
        foreach ($submittedText as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $defaults)) {
                throw new \InvalidArgumentException('The consent manager form contained an unexpected text field. Nothing was saved.');
            }
            if ($key === 'details') {
                if (!is_array($value) || !array_is_list($value) || count($value) > ConsentAppearance::MAX_DETAILS || array_filter($value, 'is_string') !== $value) {
                    throw new \InvalidArgumentException('Additional paragraphs must be text. Nothing was saved.');
                }
                $paragraphs = array_values(array_filter(array_map('trim', $value), static fn (string $paragraph): bool => $paragraph !== ''));
                if ($paragraphs !== $defaults['details']) {
                    $text['details'] = $paragraphs;
                }
            } elseif ($key === 'categories') {
                if (!is_array($value) || array_filter($value, 'is_string') !== $value) {
                    throw new \InvalidArgumentException('Category labels must be text. Nothing was saved.');
                }
                // Keep labels for categories whose tags are currently disabled.
                $labels = $saved['text']['categories'] ?? [];
                foreach ($value as $category => $label) {
                    unset($labels[$category]);
                    if (trim($label) !== '' && trim($label) !== self::defaultLabel((string) $category)) {
                        $labels[(string) $category] = $label;
                    }
                }
                if ($labels !== []) {
                    $text['categories'] = $labels;
                }
            } else {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Banner wording must be text. Nothing was saved.');
                }
                if (trim($value) !== '' && trim($value) !== $defaults[$key]) {
                    $text[$key] = $value;
                }
            }
        }
        if (!array_key_exists('categories', $submittedText) && isset($saved['text']['categories'])) {
            $text['categories'] = $saved['text']['categories'];
        }

        $theme = [];
        foreach ($form['theme'] ?? [] as $key => $value) {
            $default = ConsentAppearance::THEME_DEFAULTS[ConsentAppearance::BUILTIN][$key] ?? null;
            // Unknown keys and invalid colors reach validation for a precise message.
            if ($default === null || BrandingTheme::normalizeHexColor($value) !== $default) {
                $theme[$key] = $value;
            }
        }

        $buttons = [];
        $defaultButtons = ConsentAppearance::BUTTON_DEFAULTS[ConsentAppearance::BUILTIN];
        $show = $form['buttons']['show'] ?? implode(',', $defaultButtons['show']);
        if (!is_string($show) || !array_key_exists($show, self::arrangements())) {
            throw new \InvalidArgumentException('Choose one of the listed button arrangements. Nothing was saved.');
        }
        if ($show !== implode(',', $defaultButtons['show'])) {
            $buttons['show'] = explode(',', $show);
        }
        $reopen = $form['buttons']['reopen'] ?? $defaultButtons['reopen'];
        if ($reopen !== $defaultButtons['reopen']) {
            $buttons['reopen'] = $reopen;
        }
        if (array_diff(array_keys($form['buttons'] ?? []), ['show', 'reopen']) !== []) {
            throw new \InvalidArgumentException('The consent manager form contained an unexpected button setting. Nothing was saved.');
        }

        $privacy = trim($form['privacy_policy_url'] ?? '');

        $precheck = [];
        foreach ($form['precheck_categories'] ?? [] as $cat) {
            $cat = trim($cat);
            if ($cat !== '' && !in_array($cat, $precheck, true)) {
                $precheck[] = $cat;
            }
        }

        return [
            'enabled' => $form['enabled'] === '1', 'name' => $form['name'],
            'privacy_policy_url' => $privacy === '' ? null : $privacy,
            'precheck_categories' => $precheck === [] ? null : $precheck,
            'text' => $text === [] ? null : $text,
            'theme' => $theme === [] ? null : $theme,
            'buttons' => $buttons === [] ? null : $buttons,
        ];
    }

    /** Effective values for every field: saved overrides, otherwise defaults. */
    private static function formValues(array $consent, array $categories): array
    {
        $defaults = ConsentAppearance::DEFAULT_TEXT[ConsentAppearance::BUILTIN];
        $text = [];
        foreach ($defaults as $key => $default) {
            if (is_string($default)) {
                $text[$key] = $consent['text'][$key] ?? $default;
            }
        }
        $labels = [];
        foreach ($categories as $category) {
            $labels[$category] = $consent['text']['categories'][$category] ?? self::defaultLabel($category);
        }
        $buttons = array_replace(ConsentAppearance::BUTTON_DEFAULTS[ConsentAppearance::BUILTIN], $consent['buttons'] ?? []);

        return [
            'enabled' => $consent['enabled'], 'name' => $consent['name'],
            'privacy_policy_url' => $consent['privacy_policy_url'] ?? '',
            'precheck_categories' => $consent['precheck_categories'] ?? [],
            'text' => $text,
            'details' => array_pad($consent['text']['details'] ?? $defaults['details'], ConsentAppearance::MAX_DETAILS, ''),
            'categories' => $labels,
            'theme' => array_replace(ConsentAppearance::THEME_DEFAULTS[ConsentAppearance::BUILTIN], $consent['theme'] ?? []),
            'show' => implode(',', $buttons['show']),
            'reopen' => $buttons['reopen'],
        ];
    }

    /** The rejected form exactly as typed, bounded, over the effective values. */
    private static function formFromSubmission(array $submitted, array $form): array
    {
        $text = static fn (mixed $value, string $fallback, int $limit = 4096): string => is_string($value) ? substr($value, 0, $limit) : $fallback;
        if (in_array($submitted['enabled'] ?? null, ['0', '1'], true)) {
            $form['enabled'] = $submitted['enabled'] === '1';
        }
        $form['name'] = $text($submitted['name'] ?? null, $form['name']);
        $form['privacy_policy_url'] = $text($submitted['privacy_policy_url'] ?? null, $form['privacy_policy_url']);
        if (array_key_exists('precheck_categories', $submitted) && is_array($submitted['precheck_categories'])) {
            $form['precheck_categories'] = array_values(array_filter($submitted['precheck_categories'], 'is_string'));
        }
        $wording = is_array($submitted['text'] ?? null) ? $submitted['text'] : [];
        foreach ($form['text'] as $key => $value) {
            $form['text'][$key] = $text($wording[$key] ?? null, $value);
        }
        $details = is_array($wording['details'] ?? null) ? array_values($wording['details']) : [];
        foreach ($form['details'] as $index => $value) {
            $form['details'][$index] = $text($details[$index] ?? null, $details === [] ? $value : '');
        }
        foreach ($form['categories'] as $category => $value) {
            $form['categories'][$category] = $text($wording['categories'][$category] ?? null, $value);
        }
        $theme = is_array($submitted['theme'] ?? null) ? $submitted['theme'] : [];
        foreach ($form['theme'] as $key => $value) {
            // Color inputs can only display valid colors.
            $form['theme'][$key] = BrandingTheme::normalizeHexColor($theme[$key] ?? null) ?? $value;
        }
        $buttons = is_array($submitted['buttons'] ?? null) ? $submitted['buttons'] : [];
        if (is_string($buttons['show'] ?? null) && array_key_exists($buttons['show'], self::arrangements())) {
            $form['show'] = $buttons['show'];
        }
        if (in_array($buttons['reopen'] ?? null, ConsentAppearance::REOPEN_POSITIONS, true)) {
            $form['reopen'] = $buttons['reopen'];
        }

        return $form;
    }

    /** @return array<string, string> Every valid button list, as "reject,accept,save" => "Reject · Accept all · Save selection". */
    private static function arrangements(): array
    {
        $permutations = static function (array $items) use (&$permutations): array {
            if (count($items) <= 1) return [$items];
            $result = [];
            foreach ($items as $index => $item) {
                $rest = $items;
                unset($rest[$index]);
                foreach ($permutations(array_values($rest)) as $tail) $result[] = [$item, ...$tail];
            }

            return $result;
        };
        $options = [];
        foreach ([['reject', 'accept', 'save'], ['reject', 'accept'], ['reject', 'save']] as $set) {
            foreach ($permutations($set) as $order) {
                $options[implode(',', $order)] = implode(' · ', array_map(static fn (string $name): string => self::BUTTON_NAMES[$name], $order));
            }
        }

        return $options;
    }

    /** The banner script's label for a category without a configured label. */
    private static function defaultLabel(string $category): string
    {
        return ConsentAppearance::DEFAULT_TEXT[ConsentAppearance::BUILTIN]['categories'][$category]
            ?? ucfirst(str_replace(['-', '_'], ' ', $category));
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

    private function denyUnlessAvailableToAdmin(): void
    {
        if (!$this->config->isDashboardEnabled()) throw $this->createNotFoundException('Dashboard is disabled.');
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }
}
