<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ConsentManagerController;
use App\Service\AggregateConfigLoader;
use App\Service\ConsentAppearance;
use App\Service\SiteScriptConfig;
use App\Service\TagManagerSettings;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ConsentManagerControllerTest extends TestCase
{
    private string $projectDir;
    private AggregateConfigLoader $config;
    private SiteScriptConfig $sites;
    private string $siteId;
    private string $otherSiteId;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-consent-admin-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['admin_token' => 'private-admin-token']));
        $this->config = new AggregateConfigLoader($this->projectDir, 'test');
        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump(['websites' => [
            ['name' => 'First shop', 'domain' => 'first.example', 'token' => 'first-public-token'],
            ['name' => 'Second shop', 'domain' => 'second.example', 'token' => 'second-public-token'],
        ]]));
        $this->sites = new SiteScriptConfig(new WebsiteConfigManager($this->projectDir), $this->projectDir, 'test');
        $this->siteId = SiteScriptConfig::idForToken('first-public-token');
        $this->otherSiteId = SiteScriptConfig::idForToken('second-public-token');
        // The first website's enabled tags add a marketing category to its banner.
        $this->sites->save($this->siteId, ['enabled' => true, 'tags' => [['id' => 'ads', 'src' => 'https://ads.example/pixel.js', 'consent' => 'marketing']]], []);
        file_put_contents($this->sitePath($this->siteId), "operator_note: keep me\n", FILE_APPEND);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testPageDefaultsToTheFirstWebsiteAndShowsCurrentValuesWithAPreview(): void
    {
        $response = $this->index([]);
        $page = new Crawler((string) $response->getContent());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame($this->siteId, $page->filter('#consent-manager-site option[selected]')->attr('value'));
        self::assertSame('First shop', $page->filter('#consent-manager-scope')->text());
        self::assertSame('1', $page->filter('#consent-enabled option[selected]')->attr('value'));
        self::assertSame('First shop', $page->filter('#consent-name')->attr('value'));
        self::assertSame('{name}: privacy choices', $page->filter('#consent-text-title')->attr('value'));
        self::assertSame(ConsentAppearance::DEFAULT_TEXT['builtin']['details'][0], $page->filter('#consent-text-details-0')->text());
        self::assertSame('', $page->filter('#consent-text-details-3')->text());
        self::assertSame('Enhanced analytics', $page->filter('#consent-category-analytics')->attr('value'));
        self::assertSame('Marketing', $page->filter('#consent-category-marketing')->attr('value'));
        self::assertSame('#2459b8', $page->filter('#consent-theme-accent')->attr('value'));
        self::assertSame('reject,accept,save', $page->filter('#consent-buttons-show option[selected]')->attr('value'));
        self::assertCount(10, $page->filter('#consent-buttons-show option'));
        self::assertSame('bottom-left', $page->filter('#consent-buttons-reopen option[selected]')->attr('value'));
        self::assertSame('First shop: privacy choices', $page->filter('.consent-preview h2')->text());
        self::assertSame(['Reject all optional categories', 'Accept all optional categories', 'Save selected choices'],
            $page->filter('.consent-preview .ac-consent-actions button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertNotNull($page->filter('.consent-preview')->attr('inert'));
        self::assertCount(1, $page->filter('link[href="/assets/consent.css"]'));
        self::assertStringNotContainsString('private-admin-token', (string) $response->getContent());
    }

    public function testSavingStoresOnlyChangesAndKeepsTagsAndUnrelatedSettings(): void
    {
        $otherBefore = @file_get_contents($this->sitePath($this->otherSiteId));
        $form = $this->defaults();
        $form['enabled'] = '0';
        $form['name'] = 'Shop';
        $form['privacy_policy_url'] = ' https://www.example.com/privacy ';
        $form['text']['title'] = 'Cookies at {name}';
        $form['text']['accept'] = 'Accept everything';
        $form['text']['details'] = ['Only this.', '', '', ''];
        $form['text']['categories']['marketing'] = 'Advertising';
        $form['theme']['accent'] = '#1a4f8b';
        $form['buttons'] = ['show' => 'accept,reject', 'reopen' => 'hidden'];

        $request = $this->post($form);
        $response = $this->controller($request)->save($request);

        self::assertSame('/dashboard/consent-manager?site='.$this->siteId, $response->headers->get('Location'));
        self::assertCount(1, $request->getSession()->getFlashBag()->get('success'));
        $saved = Yaml::parseFile($this->sitePath($this->siteId));
        self::assertSame([
            'enabled' => false, 'name' => 'Shop', 'privacy_policy_url' => 'https://www.example.com/privacy',
            'text' => ['title' => 'Cookies at {name}', 'accept' => 'Accept everything', 'details' => ['Only this.'], 'categories' => ['marketing' => 'Advertising']],
            'theme' => ['accent' => '#1A4F8B'],
            'buttons' => ['show' => ['accept', 'reject'], 'reopen' => 'hidden'],
        ], $saved['consent_manager']);
        self::assertSame('ads', $saved['tag_manager']['tags'][0]['id']);
        self::assertSame('keep me', $saved['operator_note']);
        self::assertSame($otherBefore, @file_get_contents($this->sitePath($this->otherSiteId)));

        $page = new Crawler((string) $this->index(['site' => $this->siteId])->getContent());
        self::assertSame('Cookies at Shop', $page->filter('.consent-preview h2')->text());
        self::assertSame('hidden', $page->filter('#consent-buttons-reopen option[selected]')->attr('value'));
        self::assertSame('Advertising', $page->filter('#consent-category-marketing')->attr('value'));
    }

    public function testDefaultValuesRemoveEarlierChangesButKeepLabelsForCategoriesNotShown(): void
    {
        $this->sites->saveConsent($this->siteId, [
            'privacy_policy_url' => 'https://www.example.com/privacy',
            'text' => ['accept' => 'Yes', 'details' => [], 'categories' => ['marketing' => 'Ads', 'functional' => 'Helpers']],
            'theme' => ['accent' => '#1A4F8B'], 'buttons' => ['reopen' => 'bottom-right'],
        ]);
        $form = $this->defaults();
        $form['text']['accept'] = '';
        $request = $this->post($form);
        $this->controller($request)->save($request);

        self::assertSame(['enabled' => true, 'name' => 'First shop', 'text' => ['categories' => ['functional' => 'Helpers']]],
            Yaml::parseFile($this->sitePath($this->siteId))['consent_manager']);
    }

    public function testRejectedSettingsAreNotSavedAndAreShownAgainOnceForTheSameWebsite(): void
    {
        $before = file_get_contents($this->sitePath($this->siteId));
        $form = $this->defaults();
        $form['name'] = 'Typed name';
        $form['text']['reject'] = 'Typed reject';
        $form['text']['details'] = ['First typed.', '', 'Third typed.', ''];
        $form['theme']['text'] = '#eeeeee';
        $form['buttons']['show'] = 'reject,save';
        $request = $this->post($form);
        $this->controller($request)->save($request);

        $errors = $request->getSession()->getFlashBag()->get('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('consent_manager.theme: text #EEEEEE on background #FFFFFF has a contrast ratio', $errors[0]);
        self::assertSame($before, file_get_contents($this->sitePath($this->siteId)));

        self::assertCount(0, $this->view($request, $this->otherSiteId)->filter('.notification.is-warning'), 'another website never receives the draft');
        $request->getSession()->set(ConsentManagerController::SUBMITTED_FORM_KEY, ['site' => $this->siteId, 'form' => array_diff_key($form, ['_csrf_token' => true])]);
        $page = $this->view($request, $this->siteId);
        self::assertStringContainsString('Values preserved', $page->text());
        self::assertSame('Typed name', $page->filter('#consent-name')->attr('value'));
        self::assertSame('Typed reject', $page->filter('#consent-text-reject')->attr('value'));
        self::assertSame('Third typed.', $page->filter('#consent-text-details-2')->text());
        self::assertSame('', $page->filter('#consent-text-details-1')->text());
        self::assertSame('#eeeeee', $page->filter('#consent-theme-text')->attr('value'));
        self::assertSame('reject,save', $page->filter('#consent-buttons-show option[selected]')->attr('value'));
        self::assertStringNotContainsString('Values preserved', $this->view($request, $this->siteId)->text());
    }

    public function testInvalidSecurityTokenSavesAndRemembersNothing(): void
    {
        $before = file_get_contents($this->sitePath($this->siteId));
        $form = $this->defaults();
        $form['name'] = 'Forged';
        $request = $this->post($form);
        $this->controller($request, csrfValid: false)->save($request);

        self::assertSame($before, file_get_contents($this->sitePath($this->siteId)));
        self::assertFalse($request->getSession()->has(ConsentManagerController::SUBMITTED_FORM_KEY));
    }

    public function testMalformedFormsAndUnknownWebsitesAreRejected(): void
    {
        $before = file_get_contents($this->sitePath($this->siteId));
        foreach ([
            ['enabled' => 'false'], ['name' => ['x']], ['unexpected' => '1'], ['text' => ['footer' => 'x']],
            ['text' => ['details' => ['a', 'b', 'c', 'd', 'e']]], ['buttons' => ['show' => 'accept,save']], ['buttons' => ['show' => 'reject,accept', 'extra' => '1']],
            ['buttons' => ['reopen' => 'top']], ['theme' => ['shadow' => '#000000']], ['privacy_policy_url' => 'http://insecure.example'], ['site' => 'ffffffffffffffffffffffff'],
        ] as $changes) {
            $request = $this->post(array_replace($this->defaults(), $changes));
            $this->controller($request)->save($request);
            self::assertCount(1, $request->getSession()->getFlashBag()->get('error'), json_encode($changes));
            self::assertSame($before, file_get_contents($this->sitePath($this->siteId)));
        }

        $this->expectException(NotFoundHttpException::class);
        $this->index(['site' => '../../config']);
    }

    public function testMalformedYamlShowsRecoveryMessageWithoutAForm(): void
    {
        file_put_contents($this->sitePath($this->siteId), "consent_manager:\n  theme: {text: '#EEEEEE'}\n");
        $page = new Crawler((string) $this->index(['site' => $this->siteId])->getContent());

        self::assertStringContainsString('could not be loaded', $page->filter('.notification.is-danger')->text());
        self::assertCount(0, $page->filter('form#consent-manager-form'));
    }

    public function testRequiresAdministratorAndEnabledDashboard(): void
    {
        $request = Request::create('/dashboard/consent-manager');
        try {
            $this->controller($request, admin: false)->index($request);
            self::fail('A non-administrator opened the page.');
        } catch (AccessDeniedException) {
        }
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['dashboard_enabled' => false]));
        $this->config = new AggregateConfigLoader($this->projectDir, 'test');
        $this->expectException(NotFoundHttpException::class);
        $this->controller($request)->index($request);
    }

    /** The page's fields with the values it shows for unchanged defaults. */
    private function defaults(): array
    {
        $text = ConsentAppearance::DEFAULT_TEXT['builtin'];
        $fields = array_filter($text, 'is_string');

        return [
            '_csrf_token' => 'valid-token', 'site' => $this->siteId, 'enabled' => '1', 'name' => 'First shop', 'privacy_policy_url' => '',
            'text' => $fields + ['details' => array_pad($text['details'], 4, ''), 'categories' => ['analytics' => 'Enhanced analytics', 'marketing' => 'Marketing']],
            'theme' => array_map('strtolower', ConsentAppearance::THEME_DEFAULTS['builtin']),
            'buttons' => ['show' => 'reject,accept,save', 'reopen' => 'bottom-left'],
        ];
    }

    private function index(array $query): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('/dashboard/consent-manager', 'GET', $query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $this->controller($request)->index($request);
    }

    private function view(Request $previous, string $siteId): Crawler
    {
        $request = Request::create('/dashboard/consent-manager', 'GET', ['site' => $siteId]);
        $request->setSession($previous->getSession());

        return new Crawler((string) $this->controller($request)->index($request)->getContent());
    }

    private function post(array $form): Request
    {
        $request = Request::create('/dashboard/consent-manager/save', 'POST', $form);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function sitePath(string $id): string
    {
        return $this->projectDir.'/config/tag-manager/sites/'.$id.'.yaml';
    }

    private function controller(Request $request, bool $csrfValid = true, bool $admin = true): ConsentManagerController
    {
        $settings = new TagManagerSettings($this->config, $this->sites);
        $controller = new ConsentManagerController($this->config, $this->sites, $settings, new NullLogger());
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn($admin);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $csrfValid
            && $token->getId() === ConsentManagerController::CSRF_TOKEN_ID && $token->getValue() === 'valid-token');
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters = []): string => '/dashboard/consent-manager'.($parameters !== [] ? '?'.http_build_query($parameters) : ''));
        $stack = new RequestStack();
        $stack->push($request);
        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $container->set('twig', $this->twig());
        $controller->setContainer($container);

        return $controller;
    }

    private function twig(): Environment
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '{% block stylesheets %}{% endblock %}{% block body %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/assets/'.$path));
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $name): string => 'valid-token'));
        $twig->addFunction(new TwigFunction('path', static function (string $route, array $parameters = []): string {
            $path = match ($route) {
                'app_consent_manager' => '/dashboard/consent-manager',
                'app_consent_manager_save' => '/dashboard/consent-manager/save',
                'app_tag_manager' => '/dashboard/tag-manager',
                'app_setup' => '/dashboard/setup',
                'app_standalone_consent' => '/dashboard/setup/standalone/'.($parameters['siteId'] ?? ''),
            };
            unset($parameters['siteId']);

            return $path.($parameters !== [] ? '?'.http_build_query($parameters) : '');
        }));
        \App\Tests\Support\TwigComponents::register($twig);

        return $twig;
    }


    public function testLayoutCategoriesAndOtherTagsAreSavedShownAndRestoredAfterAnError(): void
    {
        $form = $this->defaults();
        $form['layout'] = 'bar';
        $form['categories'] = 'functional, preferences functional';
        $form['external_tags'] = [
            ['id' => 'meta-pixel', 'name' => ' Meta Pixel ', 'category' => 'marketing'],
            ['id' => 'chat', 'name' => 'Support chat', 'category' => 'social'],
            // Empty rows are the page's spare rows and are ignored.
            ['id' => '', 'name' => '', 'category' => ''],
        ];
        $request = $this->post($form);
        $this->controller($request)->save($request);
        self::assertSame([], $request->getSession()->getFlashBag()->get('error'));
        $saved = Yaml::parseFile($this->sitePath($this->siteId))['consent_manager'];
        self::assertSame('bar', $saved['layout']);
        self::assertSame(['functional', 'preferences'], $saved['categories']);
        self::assertSame([['id' => 'meta-pixel', 'name' => 'Meta Pixel', 'category' => 'marketing'], ['id' => 'chat', 'name' => 'Support chat', 'category' => 'social']], $saved['external_tags']);

        $page = $this->view($request, $this->siteId);
        self::assertSame('bar', $page->filter('#consent-layout option[selected]')->attr('value'));
        self::assertSame('functional, preferences', $page->filter('#consent-categories')->attr('value'));
        self::assertSame('chat', $page->filter('#consent-tag-1-id')->attr('value'));
        self::assertSame('', $page->filter('#consent-tag-2-id')->attr('value'), 'spare rows follow the saved tags');
        // Every category the banner asks about can be labelled: configured, from other tags, and from enabled tags.
        foreach (['analytics', 'functional', 'preferences', 'marketing', 'social'] as $category) {
            self::assertCount(1, $page->filter('#consent-category-'.$category), $category);
        }
        self::assertSame('Includes: Meta Pixel', $page->filter('.consent-preview [data-category-tags="Meta Pixel"]')->text());
        self::assertStringContainsString('ac-consent--bar', $page->filter('.consent-preview .ac-consent')->attr('class'));

        // A tag that would never wait for consent is rejected; the rows are shown again.
        $before = file_get_contents($this->sitePath($this->siteId));
        $form['external_tags'][] = ['id' => 'bad', 'name' => 'Bad', 'category' => 'none'];
        $request = $this->post($form);
        $this->controller($request)->save($request);
        $errors = $request->getSession()->getFlashBag()->get('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('consent_manager.external_tags[2].category', $errors[0]);
        self::assertSame($before, file_get_contents($this->sitePath($this->siteId)));
        $page = $this->view($request, $this->siteId);
        self::assertStringContainsString('Values preserved', $page->text());
        self::assertSame('none', $page->filter('#consent-tag-2-category')->attr('value'));

        // The card layout and empty lists remove the keys again.
        $form = $this->defaults() + ['layout' => 'card', 'categories' => '', 'external_tags' => [['id' => '', 'name' => '', 'category' => '']]];
        $request = $this->post($form);
        $this->controller($request)->save($request);
        self::assertSame(['enabled' => true, 'name' => 'First shop'], Yaml::parseFile($this->sitePath($this->siteId))['consent_manager']);
    }

    public function testRegionConsentTypesAreSavedListedAndValidated(): void
    {
        $form = $this->defaults();
        $form['regions'] = [
            ['id' => 'eu', 'name' => 'Europe', 'consent_type' => 'explicit_eu', 'default' => '1'],
            ['id' => 'us', 'name' => 'United States', 'consent_type' => 'explicit_us'],
            ['id' => 'ca', 'name' => 'Canada', 'consent_type' => 'implicit_us'],
            // The "Add a region" row, left empty, is ignored.
            ['id' => '', 'name' => '', 'consent_type' => 'explicit_eu'],
        ];
        $form['text']['dismiss'] = 'Hide this notice';
        $request = $this->post($form);
        $this->controller($request)->save($request);

        self::assertCount(1, $request->getSession()->getFlashBag()->get('success'));
        $saved = Yaml::parseFile($this->sitePath($this->siteId))['consent_manager'];
        self::assertSame(['explicit_eu', 'explicit_us', 'implicit_us'], array_column($saved['regions'], 'consent_type'));
        self::assertSame(['dismiss' => 'Hide this notice'], $saved['text'], 'only the changed close label is stored');

        $page = new Crawler((string) $this->index(['site' => $this->siteId])->getContent());
        self::assertSame(['implicit_us', 'explicit_us', 'explicit_eu'], $page->filter('#region-0-consent-type option')->each(static fn (Crawler $option): string => $option->attr('value')));
        self::assertSame('explicit_us', $page->filter('#region-1-consent-type option[selected]')->attr('value'));
        self::assertSame('implicit_us', $page->filter('#region-2-consent-type option[selected]')->attr('value'));
        self::assertSame('explicit_eu', $page->filter('#new-region-consent-type option[selected]')->attr('value'));
        self::assertSame('Hide this notice', $page->filter('#consent-text-dismiss')->attr('value'));
        self::assertSame('Reject all optional categories and close', $page->filter('#consent-text-dismiss_reject')->attr('value'));
        self::assertSame('Reject all optional categories and close', $page->filter('.consent-preview .ac-consent-close')->attr('aria-label'));
        self::assertSame('×', $page->filter('.consent-preview .ac-consent-close')->text());

        $form['regions'] = [['id' => 'eu', 'name' => 'Europe', 'consent_type' => 'opt_in']];
        $rejected = $this->post($form);
        $this->controller($rejected)->save($rejected);
        self::assertSame(['consent_manager.regions[0].consent_type must be one of implicit_us, explicit_us, explicit_eu.'], $rejected->getSession()->getFlashBag()->get('error'));
        self::assertSame(['explicit_eu', 'explicit_us', 'implicit_us'], array_column(Yaml::parseFile($this->sitePath($this->siteId))['consent_manager']['regions'], 'consent_type'), 'nothing was saved');
    }

    public function testConsentBehaviorIsSavedFromTheFormAndShown(): void
    {
        $page = new Crawler((string) $this->index(['site' => $this->siteId])->getContent());
        self::assertSame('explicit_eu', $page->filter('#consent-type option[selected]')->attr('value'));
        self::assertSame('', $page->filter('#consent-close-button option[selected]')->attr('value'));
        self::assertNotNull($page->filter('#consent-default-granted-analytics')->attr('checked'));
        self::assertNull($page->filter('#consent-default-granted-marketing')->attr('checked'));
        self::assertNotNull($page->filter('#consent-respect-gpc')->attr('checked'));

        $form = $this->defaults();
        $form['consent_type'] = 'implicit_us';
        $form['close_button'] = 'hidden';
        $form['default_granted'] = ['', 'analytics', 'marketing'];
        $form['respect_gpc'] = '0';
        $form['regions'] = [
            ['id' => 'us', 'name' => 'United States', 'consent_type' => 'implicit_us', 'close_button' => 'dismiss', 'default_granted_override' => '1', 'default_granted' => ['']],
            ['id' => 'eu', 'name' => 'Europe', 'consent_type' => 'explicit_eu', 'close_button' => '', 'default_granted' => ['', 'analytics']],
        ];
        $request = $this->post($form);
        $this->controller($request)->save($request);

        self::assertCount(1, $request->getSession()->getFlashBag()->get('success'));
        $saved = Yaml::parseFile($this->sitePath($this->siteId))['consent_manager'];
        self::assertSame('implicit_us', $saved['consent_type']);
        self::assertSame('hidden', $saved['close_button']);
        self::assertSame(['analytics', 'marketing'], $saved['default_granted']);
        self::assertFalse($saved['respect_gpc']);
        self::assertSame('dismiss', $saved['regions'][0]['close_button']);
        self::assertSame([], $saved['regions'][0]['default_granted'], 'an override with nothing checked turns everything off');
        self::assertArrayNotHasKey('close_button', $saved['regions'][1]);
        self::assertArrayNotHasKey('default_granted', $saved['regions'][1], 'boxes without the override checked are not saved');

        $page = new Crawler((string) $this->index(['site' => $this->siteId])->getContent());
        self::assertSame('implicit_us', $page->filter('#consent-type option[selected]')->attr('value'));
        self::assertSame('hidden', $page->filter('#consent-close-button option[selected]')->attr('value'));
        self::assertNotNull($page->filter('#consent-default-granted-marketing')->attr('checked'));
        self::assertNull($page->filter('#consent-respect-gpc')->attr('checked'));
        self::assertSame('dismiss', $page->filter('#region-0-close-button option[selected]')->attr('value'));
        self::assertNotNull($page->filter('#region-0-default-granted-override')->attr('checked'));
        self::assertNull($page->filter('#region-1-default-granted-override')->attr('checked'));

        // Defaults remove the keys again.
        $form['consent_type'] = 'explicit_eu';
        $form['close_button'] = '';
        $form['default_granted'] = ['', 'analytics'];
        $form['respect_gpc'] = '1';
        unset($form['regions']);
        $request = $this->post($form);
        $this->controller($request)->save($request);
        $saved = Yaml::parseFile($this->sitePath($this->siteId))['consent_manager'];
        foreach (['consent_type', 'close_button', 'default_granted', 'respect_gpc'] as $key) {
            self::assertArrayNotHasKey($key, $saved, $key);
        }

        // An explicit region cannot have default-on categories.
        $form['regions'] = [['id' => 'eu', 'name' => 'Europe', 'consent_type' => 'explicit_eu', 'default_granted_override' => '1', 'default_granted' => ['', 'analytics']]];
        $rejected = $this->post($form);
        $this->controller($rejected)->save($rejected);
        self::assertSame(['consent_manager.regions[0].default_granted applies only to implicit_us regions; explicit consent types start every optional category denied.'], $rejected->getSession()->getFlashBag()->get('error'));
    }

    public function testSavingTheFormKeepsRegionWordingAndButtonsItDoesNotShow(): void
    {
        $this->sites->saveConsent($this->siteId, ['regions' => [[
            'id' => 'us', 'name' => 'United States', 'consent_type' => 'explicit_us',
            'text' => ['title' => 'US notice', 'dismiss' => 'Close US notice', 'details' => ['US paragraph.']],
            'buttons' => ['show' => ['reject', 'accept']],
        ]]]);

        $form = $this->defaults();
        $form['regions'] = [['id' => 'us', 'name' => 'United States', 'consent_type' => 'explicit_us', 'text' => [
            'title' => 'Changed US notice', 'description' => '', 'accept' => '', 'reject' => '',
        ]]];
        $request = $this->post($form);
        $this->controller($request)->save($request);

        self::assertCount(1, $request->getSession()->getFlashBag()->get('success'));
        $region = Yaml::parseFile($this->sitePath($this->siteId))['consent_manager']['regions'][0];
        self::assertSame(['title' => 'Changed US notice', 'dismiss' => 'Close US notice', 'details' => ['US paragraph.']], $region['text']);
        self::assertSame(['show' => ['reject', 'accept']], $region['buttons']);

        // A field the form shows and the operator cleared falls back to the website's wording.
        $form['regions'][0]['text']['title'] = '';
        $request = $this->post($form);
        $this->controller($request)->save($request);
        $region = Yaml::parseFile($this->sitePath($this->siteId))['consent_manager']['regions'][0];
        self::assertSame(['dismiss' => 'Close US notice', 'details' => ['US paragraph.']], $region['text']);
    }

    public function testPrecheckCategoriesAreSavedAndRenderedInFormAndPreview(): void
    {
        $form = $this->defaults();
        $form["precheck_categories"] = ["analytics", "marketing"];
        $request = $this->post($form);
        $this->controller($request)->save($request);

        $saved = Yaml::parseFile($this->sitePath($this->siteId));
        self::assertSame(["analytics", "marketing"], $saved["consent_manager"]["precheck_categories"]);

        $page = new Crawler((string) $this->index(["site" => $this->siteId])->getContent());
        self::assertNotNull($page->filter("#consent-precheck-analytics")->attr("checked"));
        self::assertNotNull($page->filter('[data-preview-checkbox="analytics"]')->attr("checked"));

        // Clearing precheck removes it from YAML
        $form["precheck_categories"] = [];
        $request = $this->post($form);
        $this->controller($request)->save($request);

        $savedAfter = Yaml::parseFile($this->sitePath($this->siteId));
        self::assertArrayNotHasKey("precheck_categories", $savedAfter["consent_manager"]);
    }
}
