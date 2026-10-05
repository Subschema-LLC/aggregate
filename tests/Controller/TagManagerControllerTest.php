<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\TagManagerController;
use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use App\Service\DropInScripts;
use App\Service\SiteScriptConfig;
use App\Service\TagManagerSettings;
use App\Service\WebsiteConfigManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class TagManagerControllerTest extends TestCase
{
    private string $projectDir;
    private AggregateConfigLoader $config;
    private SiteScriptConfig $sites;
    private string $siteId;
    private string $otherSiteId;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-tag-admin-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'admin_token' => 'private-admin-token',
            'app_host' => 'https://analytics.example',
            'environments' => [
                'test' => ['updates_branch' => 'uat'],
                'prod' => ['tag_manager' => ['enabled' => false]],
            ],
        ], 5, 2));
        $this->config = new AggregateConfigLoader($this->projectDir, 'test');
        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump(['websites' => [
            ['name' => 'First shop', 'domain' => 'first.example', 'token' => 'first-public-token'],
            ['name' => 'Second shop', 'domain' => 'second.example', 'token' => 'second-public-token'],
        ]]));
        $this->sites = new SiteScriptConfig(new WebsiteConfigManager($this->projectDir), $this->projectDir, 'test');
        $this->siteId = SiteScriptConfig::idForToken('first-public-token');
        $this->otherSiteId = SiteScriptConfig::idForToken('second-public-token');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testAdminFormSavesStrictBooleansAndSkipsBlankAndRemovedRows(): void
    {
        $form = self::validForm();
        $form['tags'][] = ['id' => 'removed', 'src' => 'https://scripts.example/removed.js', 'enabled' => '1', 'remove' => '1'];
        $form['tags'][] = ['id' => '', 'src' => '', 'enabled' => '1'];
        $request = $this->request($form);
        $response = $this->controller($request)->save($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/dashboard/tag-manager?site=', $response->headers->get('Location'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $saved = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('private-admin-token', $saved['admin_token']);
        self::assertSame('uat', $saved['environments']['test']['updates_branch']);
        self::assertSame(['tag_manager' => ['enabled' => false]], $saved['environments']['prod']);
        self::assertSame(['enabled' => true, 'variables' => [], 'tags' => [
            ['id' => 'analytics', 'src' => 'https://scripts.example/analytics.js', 'enabled' => true, 'consent' => 'analytics', 'trigger' => ['type' => 'dom_ready'], 'type' => 'script'],
        ]], $saved['environments']['test']['tag_manager']);
        self::assertCount(1, $request->getSession()->getFlashBag()->peek('success'));
    }

    #[DataProvider('invalidForms')]
    public function testForgedOrMalformedFormDoesNotSave(array $form): void
    {
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $request = $this->request($form);
        $response = $this->controller($request)->save($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertCount(1, $request->getSession()->getFlashBag()->peek('error'));
        self::assertCount(0, $request->getSession()->getFlashBag()->peek('success'));
    }

    public static function invalidForms(): iterable
    {
        $valid = self::validForm();
        yield 'missing' => [[]];
        yield 'string false' => [array_replace($valid, ['enabled' => 'false'])];
        yield 'boolean enabled' => [array_replace($valid, ['enabled' => true])];
        yield 'array enabled' => [array_replace($valid, ['enabled' => ['1']])];
        yield 'unrelated setting' => [$valid + ['admin_token' => 'forged']];
        yield 'tags not array' => [array_replace($valid, ['tags' => 'script'])];
        yield 'tags map' => [array_replace($valid, ['tags' => ['named' => $valid['tags'][0]]])];
        yield 'null site' => [array_replace($valid, ['site' => null])];
        yield 'array site' => [array_replace($valid, ['site' => ['unknown']])];
        yield 'unknown site' => [array_replace($valid, ['site' => str_repeat('f', 24)])];
        yield 'path site' => [array_replace($valid, ['site' => '../../private'])];
        yield 'variables string' => [array_replace($valid, ['variables' => 'site.plan'])];
        yield 'variable row shape' => [array_replace($valid, ['variables' => [['alias' => 'plan', 'path' => []]]])];
        yield 'variable extra key' => [array_replace($valid, ['variables' => [['alias' => 'plan', 'path' => 'site.plan', 'code' => 'alert(1)']]])];
        yield 'variable duplicate' => [array_replace($valid, ['variables' => [['alias' => 'plan', 'path' => 'site.plan'], ['alias' => 'plan', 'path' => 'site.other']]])];
        yield 'variable getter expression' => [array_replace($valid, ['variables' => [['alias' => 'plan', 'path' => 'site.plan()']]])];
        yield 'legacy consent controls' => [array_replace($valid, ['consent_manager' => ['enabled' => '1', 'name' => 'Site']])];
        foreach ([
            ['enabled' => 'false'], ['enabled' => ['1']], ['src' => []], ['id' => []],
            ['src' => 'javascript:alert(1)'], ['remove' => 'false'], ['remove' => ['1']],
            ['consent_required' => false], ['code' => 'alert(1)'], ['consent' => []], ['consent' => 'No consent'],
            ['method' => 'Acme.track'], ['args_json' => '["wrong action"]'], ['args_json' => []],
            ['trigger' => ['type' => 'document_event', 'event' => 'bad event']], ['trigger' => ['type' => 'dom_ready', 'event' => 'click']],
        ] as $index => $changes) {
            yield 'invalid row '.$index => [array_replace($valid, ['tags' => [array_replace($valid['tags'][0], $changes)]])];
        }
    }

    #[DataProvider('invalidTokens')]
    public function testMalformedOrInvalidCsrfDoesNotSave(mixed $token): void
    {
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $request = $this->request(array_replace(self::validForm(), ['_csrf_token' => $token]));
        $response = $this->controller($request, false)->save($request);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame(['Invalid security token. Please try again.'], $request->getSession()->getFlashBag()->peek('error'));
    }

    public static function invalidTokens(): iterable
    {
        yield 'missing' => [null];
        yield 'wrong' => ['wrong'];
        yield 'array' => [['valid-token']];
    }

    public function testMalformedExistingConfigurationCannotBeReplacedByAValidForm(): void
    {
        $yaml = "tag_manager:\n  enabled: 'false'\n";
        file_put_contents($this->projectDir.'/config/aggregate.yaml', $yaml);
        $request = $this->request(self::validForm());
        $this->controller($request)->save($request);
        self::assertSame($yaml, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertCount(1, $request->getSession()->getFlashBag()->peek('error'));
    }

    public function testSiteFormAtomicallySavesVariablesCallArgumentsTriggersAndConsentWithoutChangingOtherSites(): void
    {
        $this->sites->save($this->otherSiteId, ['enabled' => true, 'tags' => [['id' => 'other', 'src' => 'https://scripts.example/other.js']]], ['enabled' => false, 'name' => 'Second choices']);
        $globalBefore = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $otherBefore = file_get_contents($this->sitePath($this->otherSiteId));
        file_put_contents($this->sitePath($this->siteId), "unrelated_setting: private-site-value\n");
        $form = $this->siteForm();
        $request = $this->request($form);
        $response = $this->controller($request)->save($request);

        self::assertSame('/dashboard/tag-manager?site='.$this->siteId, $response->headers->get('Location'));
        self::assertCount(1, $request->getSession()->getFlashBag()->peek('success'));
        self::assertSame($globalBefore, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame($otherBefore, file_get_contents($this->sitePath($this->otherSiteId)));
        $saved = Yaml::parseFile($this->sitePath($this->siteId));
        self::assertSame('private-site-value', $saved['unrelated_setting']);
        self::assertSame(['enabled' => true, 'name' => 'First shop choices'], $saved['consent_manager']);
        self::assertSame(['plan' => 'window.siteData.plan'], $saved['tag_manager']['variables']);
        self::assertCount(2, $saved['tag_manager']['tags']);
        self::assertSame('script', $saved['tag_manager']['tags'][0]['type']);
        self::assertSame('https://scripts.example/helper.js?plan={{plan}}', $saved['tag_manager']['tags'][0]['src']);
        self::assertSame(['type' => 'window_load'], $saved['tag_manager']['tags'][0]['trigger']);
        $call = $saved['tag_manager']['tags'][1];
        self::assertSame('call', $call['type']);
        self::assertSame('Aggregate.emit', $call['method']);
        self::assertSame(['purchase', ['total_minor' => 1299, 'discount' => 0.1, 'plan' => ['$var' => 'plan'], 'is_trial' => false, 'missing' => null]], $call['args']);
        self::assertSame(['type' => 'data_layer', 'event' => 'purchase'], $call['trigger']);
        self::assertArrayNotHasKey('src', $call);
        self::assertArrayNotHasKey('args_json', $call);
    }

    #[DataProvider('invalidSiteFields')]
    public function testInvalidSiteArgumentsOrConsentNeverPartiallySave(array $changes): void
    {
        $this->sites->save($this->siteId, TagManagerSettings::DEFAULTS, ['enabled' => true, 'name' => 'Existing choices']);
        $before = file_get_contents($this->sitePath($this->siteId));
        $form = $this->siteForm();
        foreach ($changes as $key => $value) {
            if ($key === 'args_json' || $key === 'method' || $key === 'src') $form['tags'][1][$key] = $value;
            else $form[$key] = $value;
        }
        $request = $this->request($form);
        $this->controller($request)->save($request);
        self::assertCount(1, $request->getSession()->getFlashBag()->peek('error'));
        self::assertCount(0, $request->getSession()->getFlashBag()->peek('success'));
        self::assertSame($before, file_get_contents($this->sitePath($this->siteId)));
    }

    public static function invalidSiteFields(): iterable
    {
        yield 'code as JSON' => [['args_json' => 'Acme.track()']];
        yield 'invalid JSON' => [['args_json' => '["purchase",']];
        yield 'JSON object top level' => [['args_json' => '{"0":"purchase"}']];
        yield 'prototype literal' => [['args_json' => '[{"__proto__":{"polluted":true}}]']];
        yield 'unknown ref' => [['args_json' => '[{"$var":"missing"}]']];
        yield 'unsafe method' => [['method' => 'window.eval']];
        yield 'simultaneous script URL' => [['src' => 'https://scripts.example/conflict.js']];
        yield 'missing CMP fields' => [['consent_manager' => null]];
        yield 'string false CMP' => [['consent_manager' => ['enabled' => 'false', 'name' => 'Name']]];
        yield 'empty CMP name' => [['consent_manager' => ['enabled' => '0', 'name' => '']]];
        yield 'extra CMP policy' => [['consent_manager' => ['enabled' => '1', 'name' => 'Name', 'categories' => ['marketing']]]];
        yield 'removed referenced variable' => [['variables' => [['alias' => 'plan', 'path' => 'site.plan', 'remove' => '1']]]];
    }

    public function testWebsiteSelectorDefaultsToFirstSiteAndRendersActionsVariablesAndIndependentConsent(): void
    {
        $request = $this->request($this->siteForm());
        $this->controller($request)->save($request);
        $viewRequest = Request::create('/dashboard/tag-manager');
        $response = $this->controller($viewRequest)->index($viewRequest);
        $crawler = new Crawler((string) $response->getContent());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->siteId, $crawler->filter('#tag-manager-site option[selected]')->attr('value'));
        self::assertSame('First shop', $crawler->filter('#tag-manager-scope')->text());
        self::assertSame($this->siteId, $crawler->filter('input[name="site"]')->attr('value'));
        self::assertSame('First shop choices', $crawler->filter('#site-consent-name')->attr('value'));
        self::assertSame('window.siteData.plan', $crawler->filter('#variable-0-path')->attr('value'));
        self::assertSame('call', $crawler->filter('#tag-1-type option[selected]')->attr('value'));
        self::assertSame('Aggregate.emit', $crawler->filter('#tag-1-method')->attr('value'));
        self::assertSame(['purchase', ['total_minor' => 1299, 'discount' => 0.1, 'plan' => ['$var' => 'plan'], 'is_trial' => false, 'missing' => null]], json_decode($crawler->filter('#tag-1-args')->text(), true, flags: JSON_THROW_ON_ERROR));
        self::assertCount(1, $crawler->filter('a[href="/dashboard/tag-manager/download?site='.$this->siteId.'"]'));
        self::assertStringContainsString('Trigger and event reference', $crawler->text());
        self::assertStringNotContainsString('private-admin-token', $response->getContent());

        $otherRequest = Request::create('/dashboard/tag-manager', 'GET', ['site' => $this->otherSiteId]);
        $other = new Crawler((string) $this->controller($otherRequest)->index($otherRequest)->getContent());
        self::assertSame('Second shop', $other->filter('#tag-manager-scope')->text());
        self::assertSame('0', $other->filter('#tag-manager-enabled option[selected]')->attr('value'));
        self::assertSame('Second shop', $other->filter('#site-consent-name')->attr('value'));
        self::assertSame('', $other->filter('#tag-0-id')->attr('value'));

        $legacyRequest = Request::create('/dashboard/tag-manager', 'GET', ['site' => '']);
        $legacy = new Crawler((string) $this->controller($legacyRequest)->index($legacyRequest)->getContent());
        self::assertSame('', $legacy->filter('#tag-manager-site option[selected]')->attr('value'));
        self::assertSame('Shared configuration', $legacy->filter('#tag-manager-scope')->text());
        self::assertSame('Shared configuration', $legacy->filter('#tag-manager-site option[selected]')->text());
        self::assertCount(0, $legacy->filter('[name="consent_manager[name]"]'));
    }

    public function testDownloadExportsOnlySelectedPublicSettingsAndRequiresAdmin(): void
    {
        $save = $this->request($this->siteForm());
        $this->controller($save)->save($save);
        file_put_contents($this->sitePath($this->siteId), "private_extra: private-site-secret\n", FILE_APPEND);
        $request = Request::create('/dashboard/tag-manager/download', 'GET', ['site' => $this->siteId]);
        $response = $this->controller($request)->download($request);
        $export = Yaml::parse((string) $response->getContent());
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('attachment; filename="website-scripts-'.$this->siteId.'.yaml"', $response->headers->get('Content-Disposition'));
        self::assertSame(['tag_manager', 'consent_manager'], array_keys($export));
        self::assertSame('Aggregate.emit', $export['tag_manager']['tags'][1]['method']);
        self::assertSame('First shop choices', $export['consent_manager']['name']);
        self::assertStringNotContainsString('private-', $response->getContent());
        self::assertStringNotContainsString('second-public-token', $response->getContent());

        $legacy = Request::create('/dashboard/tag-manager/download', 'GET', ['site' => '']);
        self::assertSame(['tag_manager'], array_keys(Yaml::parse((string) $this->controller($legacy)->download($legacy)->getContent())));
        $unknown = Request::create('/dashboard/tag-manager/download', 'GET', ['site' => '../../private']);
        self::assertSame(400, $this->controller($unknown)->download($unknown)->getStatusCode());
        $this->expectException(AccessDeniedException::class);
        $this->controller($request, admin: false)->download($request);
    }

    public function testUnknownWebsiteCannotSilentlyOpenDeploymentDefaults(): void
    {
        $request = Request::create('/dashboard/tag-manager', 'GET', ['site' => str_repeat('f', 24)]);
        $this->expectException(NotFoundHttpException::class);
        $this->controller($request)->index($request);
    }

    public function testMalformedSiteConfigurationShowsRecoveryMessageWithoutEditableDefaults(): void
    {
        $this->sites->ensureDirectory();
        file_put_contents($this->sitePath($this->siteId), "consent_manager:\n  enabled: 'false'\n");
        $request = Request::create('/dashboard/tag-manager', 'GET', ['site' => $this->siteId]);
        $crawler = new Crawler((string) $this->controller($request)->index($request)->getContent());
        self::assertCount(1, $crawler->filter('[role="alert"]'));
        self::assertCount(0, $crawler->filter('#tag-manager-form'));
    }

    private function sitePath(string $id): string
    {
        return $this->projectDir.'/config/tag-manager/sites/'.$id.'.yaml';
    }

    private function siteForm(): array
    {
        return [
            'site' => $this->siteId, 'enabled' => '1',
            'consent_manager' => ['enabled' => '1', 'name' => 'First shop choices'],
            'variables' => [
                ['alias' => 'plan', 'path' => 'window.siteData.plan'],
                ['alias' => 'remove_me', 'path' => 'site.removed', 'remove' => '1'],
                ['alias' => '', 'path' => ''],
            ],
            'tags' => [
                ['id' => 'helper', 'type' => 'script', 'src' => 'https://scripts.example/helper.js?plan={{plan}}', 'enabled' => '1', 'consent' => 'none', 'trigger' => ['type' => 'window_load', 'event' => '']],
                ['id' => 'purchase', 'type' => 'call', 'method' => 'Aggregate.emit', 'args_json' => '["purchase", {"total_minor":1299,"discount":0.1,"plan":{"$var":"plan"},"is_trial":false,"missing":null}]', 'enabled' => '1', 'consent' => 'analytics', 'trigger' => ['type' => 'data_layer', 'event' => 'purchase']],
            ],
        ];
    }

    private static function validForm(): array
    {
        return ['site' => '', 'enabled' => '1', 'variables' => [], 'tags' => [['id' => 'analytics', 'src' => 'https://scripts.example/analytics.js', 'enabled' => '1', 'consent' => 'analytics']]];
    }

    private function request(array $form): Request
    {
        $request = Request::create('/dashboard/tag-manager/save', 'POST', $form + ['_csrf_token' => 'valid-token']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    public function testUrlPastedFromAnHtmlSnippetIsSavedWithQuerySeparators(): void
    {
        $tracker = 'https://analytics.example/aggregate.js?min=1&endpoint=https%3A%2F%2Fanalytics.example%2Fapi%2Freceive&token=first-public-token&consent=0';
        $form = $this->siteForm();
        $form['tags'][0]['src'] = str_replace('&', '&amp;', $tracker);
        $request = $this->request($form);
        $this->controller($request)->save($request);

        self::assertCount(1, $request->getSession()->getFlashBag()->peek('success'));
        self::assertSame($tracker, Yaml::parseFile($this->sitePath($this->siteId))['tag_manager']['tags'][0]['src']);
    }

    public function testRejectedFormIsShownAgainOnceForTheSameWebsiteWithoutSaving(): void
    {
        $this->sites->save($this->siteId, TagManagerSettings::DEFAULTS, ['enabled' => true, 'name' => 'Existing choices']);
        $before = file_get_contents($this->sitePath($this->siteId));
        $form = $this->siteForm();
        $form['consent_manager']['name'] = 'Typed choices';
        $form['variables'][] = ['alias' => 'region', 'path' => 'window.siteData.region'];
        $form['tags'][0]['src'] = 'https://scripts.example/typed.js?plan={{plan}}';
        $form['tags'][1]['args_json'] = '["purchase", {"total_minor": 1299'; // invalid JSON
        $form['tags'][] = ['id' => 'second-try', 'type' => 'script', 'src' => 'https://scripts.example/second.js', 'enabled' => '0', 'consent' => 'Marketing!', 'trigger' => ['type' => 'document_event', 'event' => 'cart:updated']];
        $form['tags'][] = ['id' => '', 'type' => 'script', 'src' => '', 'enabled' => '1', 'consent' => 'analytics', 'trigger' => ['type' => 'dom_ready']];
        $request = $this->request($form);
        $this->controller($request)->save($request);
        self::assertCount(1, $request->getSession()->getFlashBag()->get('error'));
        self::assertSame($before, file_get_contents($this->sitePath($this->siteId)));

        $view = $this->view($request, ['site' => $this->siteId]);
        self::assertStringContainsString('Values preserved', $view->text());
        self::assertSame('Typed choices', $view->filter('#site-consent-name')->attr('value'));
        self::assertSame('1', $view->filter('#tag-manager-enabled option[selected]')->attr('value'));
        self::assertSame('window.siteData.plan', $view->filter('#variable-0-path')->attr('value'));
        self::assertSame('remove_me', $view->filter('#variable-1-alias')->attr('value'));
        self::assertCount(1, $view->filter('input[name="variables[1][remove]"][checked]'), 'a remove choice is kept');
        self::assertSame('region', $view->filter('#variable-2-alias')->attr('value'), 'blank variable rows are not restored as entries');
        self::assertSame('', $view->filter('#variable-3-alias')->attr('value'));
        self::assertCount(0, $view->filter('#variable-4-alias'));
        self::assertCount(1, $view->filter('details[open] #variable-2-alias'));
        self::assertSame('https://scripts.example/typed.js?plan={{plan}}', $view->filter('#tag-0-src')->attr('value'));
        self::assertSame('call', $view->filter('#tag-1-type option[selected]')->attr('value'));
        self::assertSame('["purchase", {"total_minor": 1299', $view->filter('#tag-1-args')->text());
        self::assertSame('second-try', $view->filter('#tag-2-id')->attr('value'));
        self::assertSame('Marketing!', $view->filter('#tag-2-consent')->attr('value'));
        self::assertSame('0', $view->filter('#tag-2-enabled option[selected]')->attr('value'));
        self::assertSame('document_event', $view->filter('#tag-2-trigger option[selected]')->attr('value'));
        self::assertSame('cart:updated', $view->filter('#tag-2-event')->attr('value'));
        self::assertSame('', $view->filter('#tag-3-id')->attr('value'), 'one empty row for a new tag');
        self::assertCount(0, $view->filter('#tag-4-id'));

        $again = $this->view($request, ['site' => $this->siteId]);
        self::assertStringNotContainsString('Values preserved', $again->text());
        self::assertSame('Existing choices', $again->filter('#site-consent-name')->attr('value'));
        self::assertSame('', $again->filter('#tag-0-id')->attr('value'));
    }

    public function testRejectedFormIsNotShownForAnotherWebsite(): void
    {
        $form = $this->siteForm();
        $form['tags'][1]['method'] = 'window.eval';
        $request = $this->request($form);
        $this->controller($request)->save($request);

        $other = $this->view($request, ['site' => $this->otherSiteId]);
        self::assertStringNotContainsString('Values preserved', $other->text());
        self::assertSame('', $other->filter('#tag-0-id')->attr('value'));
        $first = $this->view($request, ['site' => $this->siteId]);
        self::assertStringNotContainsString('Values preserved', $first->text(), 'a rejected form is offered once');
    }

    public function testRejectedFormKeepsRemoveChoice(): void
    {
        $form = $this->siteForm();
        $form['tags'][0]['remove'] = '1';
        $form['tags'][1]['method'] = 'window.eval';
        $request = $this->request($form);
        $this->controller($request)->save($request);

        $view = $this->view($request, ['site' => $this->siteId]);
        self::assertSame('window.eval', $view->filter('#tag-1-method')->attr('value'));
        self::assertCount(1, $view->filter('input[name="tags[0][remove]"][checked]'));
        self::assertCount(0, $view->filter('input[name="tags[1][remove]"][checked]'));
    }

    public function testFormWithInvalidCsrfIsNeverKeptForPrefilling(): void
    {
        $form = $this->siteForm();
        $form['tags'][0]['src'] = 'https://attacker.example/inject.js';
        $request = $this->request($form);
        $this->controller($request, false)->save($request);

        self::assertFalse($request->getSession()->has(TagManagerController::SUBMITTED_FORM_KEY));
        $view = $this->view($request, ['site' => $this->siteId]);
        self::assertStringNotContainsString('attacker.example', $view->html());
    }

    public function testWebsiteTrackerUrlIsOfferedUntilATagLoadsIt(): void
    {
        $tracker = 'https://analytics.example/aggregate.js?min=1&endpoint=https%3A%2F%2Fanalytics.example%2Fapi%2Freceive&token=first-public-token&consent=0';
        $request = Request::create('/dashboard/tag-manager', 'GET', ['site' => $this->siteId]);
        $view = new Crawler((string) $this->controller($request)->index($request)->getContent());

        self::assertSame($tracker, $view->filter('#tag-manager-tracker-url')->text());
        self::assertStringContainsString('first.example', $view->filter('#tag-manager-tracker-title')->text());
        $add = $view->filter('[data-action="pages--tag-manager--index#addTracker"]');
        self::assertSame($tracker, $add->attr('data-pages--tag-manager--index-url-param'));
        self::assertSame(TagManagerController::TRACKER_TAG_ID, $add->attr('data-pages--tag-manager--index-id-param'));
        self::assertSame('0', $view->filter('.tag-settings')->attr('data-pages--tag-manager--index-new-row-value'));
        self::assertSame('tag-script-suggestions', $view->filter('#tag-0-src')->attr('list'));
        self::assertSame($tracker, $view->filter('#tag-script-suggestions option')->attr('value'));

        $this->sites->save($this->siteId, ['enabled' => true, 'tags' => [['id' => 'sdk', 'src' => $tracker, 'consent' => 'none']]], ['enabled' => true, 'name' => 'First shop']);
        $configured = new Crawler((string) $this->controller($request)->index($request)->getContent());
        self::assertStringContainsString('loaded by the tag sdk', $configured->filter('#tag-manager-tracker-title')->ancestors()->first()->text());
        self::assertCount(0, $configured->filter('[data-action="pages--tag-manager--index#addTracker"]'));
        self::assertCount(0, $configured->filter('#tag-script-suggestions'));
        self::assertNull($configured->filter('#tag-1-src')->attr('list'));

        $shared = Request::create('/dashboard/tag-manager', 'GET', ['site' => '']);
        self::assertCount(0, (new Crawler((string) $this->controller($shared)->index($shared)->getContent()))->filter('#tag-manager-tracker-url'));
    }

    private function view(Request $previous, array $query): Crawler
    {
        $request = Request::create('/dashboard/tag-manager', 'GET', $query);
        $request->setSession($previous->getSession());

        return new Crawler((string) $this->controller($request)->index($request)->getContent());
    }

    private function controller(Request $request, bool $csrfValid = true, bool $admin = true): TagManagerController
    {
        $settings = new TagManagerSettings($this->config, $this->sites);
        $scripts = new DropInScripts($this->config, new WebsiteConfigManager($this->projectDir), new AppBranding($this->config, $this->projectDir), $this->projectDir, $settings, $this->sites);
        $controller = new TagManagerController($this->config, $settings, new NullLogger(), $this->sites, $scripts);
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->with('ROLE_ADMIN')->willReturn($admin);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $submittedToken = $request->request->all()['_csrf_token'] ?? null;
        $csrf->expects(is_string($submittedToken) ? self::once() : self::never())->method('isTokenValid')
            ->with(self::callback(static fn (CsrfToken $token): bool => $token->getId() === TagManagerController::CSRF_TOKEN_ID && $token->getValue() === $submittedToken))
            ->willReturn($csrfValid);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters = []): string => '/dashboard/tag-manager'.($parameters !== [] ? '?'.http_build_query($parameters) : ''));
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
            new ArrayLoader(['base.html.twig' => '{% block stylesheets %}{% endblock %}{% block body %}{% endblock %}{% block javascripts %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/assets/'.$path));
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $name): string => 'valid-token'));
        $twig->addFunction(new TwigFunction('path', static function (string $route, array $parameters = []): string {
            $path = match ($route) {
                'app_tag_manager' => '/dashboard/tag-manager',
                'app_tag_manager_save' => '/dashboard/tag-manager/save',
                'app_tag_manager_download' => '/dashboard/tag-manager/download',
                'app_setup' => '/dashboard/setup',
            };

            return $path.($parameters !== [] ? '?'.http_build_query($parameters) : '');
        }));

        \App\Tests\Support\TwigComponents::register($twig);

        return $twig;
    }
}
