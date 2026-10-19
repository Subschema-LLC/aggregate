<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AuditTrailController;
use App\EventSubscriber\AdminAuditSubscriber;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\AuditTrailLog;
use App\Service\Operations\AuditTrailSettings;
use App\Tests\Service\Operations\OperationsDatabase;
use App\Tests\Support\TwigComponents;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

final class AuditTrailControllerTest extends TestCase
{
    private string $projectDir;
    private array $environment;
    private Connection $connection;
    private Session $session;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['DASHBOARD_ENABLED', 'AUDIT_TRAIL_RETENTION_DAYS', 'AUDIT_TRAIL_ADMIN_RETENTION_DAYS'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-audit-trail-page-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['brand_name' => 'Kept', 'audit_trail_admin_retention_days' => 1095]));
        $this->connection = OperationsDatabase::connection();
        $trail = new AuditTrail($this->connection);
        foreach ([
            ['2026-10-01 08:00:00', 'settings', 'branding_saved', 'succeeded', null, 'scott', null, 'Changed aggregate.yaml: brand_name'],
            ['2026-10-02 09:30:00', 'users', 'user_created', 'succeeded', 'ana', 'scott', null, 'Viewer account.'],
            ['2026-10-03 00:00:00', 'task', 'bigquery_sync', 'failed', 'bi_anonymous_events_v1', null, 41, '=HYPERLINK("http://example.test") '.str_repeat('long ', 40)],
            ['2026-10-04 12:00:00', 'users', 'password_changed', 'denied', 'scott', 'viewer', null, null],
        ] as [$time, $area, $action, $outcome, $subject, $user, $task, $details]) {
            $trail->record($area, $action, $outcome, new \DateTimeImmutable($time.' UTC'), $subject, $user, $task, $details);
        }
        $this->session = new Session(new MockArraySessionStorage());
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        array_map(unlink(...), glob($this->projectDir.'/config/*') ?: []);
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testThePageListsFiltersAndPagesTheEntries(): void
    {
        $crawler = $this->page(['area' => ['users'], 'outcome' => ['succeeded', 'denied'], 'per_page' => '25']);

        self::assertSame('Audit trail', $crawler->filter('h1')->text());
        self::assertSame('2 entries match', $crawler->filter('#audit-entries-title')->text());
        $rows = $crawler->filter('.audit-trail-entries tbody tr');
        self::assertCount(2, $rows);
        self::assertStringContainsString('2026-10-04 12:00:00', $rows->eq(0)->text());
        self::assertStringContainsString('Password changed', $rows->eq(0)->text());
        self::assertStringContainsString('Denied', $rows->eq(0)->text());
        self::assertStringContainsString('User created', $rows->eq(1)->text());
        // The form shows the filter, and each active filter can be removed on its own.
        self::assertSame('checked', $crawler->filter('input[name="area[]"][value="users"]')->attr('checked'));
        self::assertNull($crawler->filter('input[name="area[]"][value="settings"]')->attr('checked'));
        self::assertSame('selected', $crawler->filter('#audit-per-page option[value="25"]')->attr('selected'));
        $remove = $crawler->filter('.tags a[aria-label="Remove the outcome Denied"]')->attr('href');
        self::assertSame('/dashboard/audit-trail?'.http_build_query(['area' => ['users'], 'outcome' => ['succeeded'], 'per_page' => '25']), $remove);
        // A value in a row narrows the list to it, keeping the other filters.
        self::assertSame('/dashboard/audit-trail?'.http_build_query(['area' => ['users'], 'outcome' => ['succeeded', 'denied'], 'per_page' => '25', 'user' => 'viewer']), $rows->eq(0)->filter('a[title="Only this user"]')->attr('href'));
        self::assertSame('/dashboard/audit-trail/export?'.http_build_query(['area' => ['users'], 'outcome' => ['succeeded', 'denied'], 'per_page' => '25']), $crawler->filter('a[aria-describedby="audit-export-help"]')->attr('href'));
        // The choices come from what was recorded.
        self::assertSame(['bigquery_sync', 'branding_saved', 'password_changed', 'user_created'], $crawler->filter('#audit-action option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['', '-', 'scott', 'viewer'], $crawler->filter('#audit-user option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertStringContainsString('administrator actions and sign-ins for 1,095 days', $crawler->filter('[aria-labelledby="audit-retention-title"]')->text());
    }

    public function testLongDetailsFoldAndJobRunsLinkToTheirEntries(): void
    {
        $crawler = $this->page(['task' => '41']);

        self::assertSame('1 entry match', $crawler->filter('#audit-entries-title')->text());
        self::assertCount(1, $crawler->filter('.audit-trail-details details'));
        self::assertSame('/dashboard/audit-trail?task=41', $crawler->filter('.audit-trail-details a')->attr('href'));
        self::assertStringContainsString('Job run 41', $crawler->filter('.tags')->text());
    }

    public function testPagesKeepTheFilters(): void
    {
        $crawler = $this->page(['per_page' => '25', 'page' => '1', 'q' => 's']);
        self::assertCount(0, $crawler->filter('nav.pagination'), 'one page needs no paging');

        $trail = new AuditTrail($this->connection);
        for ($number = 0; $number < 60; ++$number) {
            $trail->record('operations', 'glossary_synced', 'succeeded', new \DateTimeImmutable('2026-10-05 10:00:00 UTC'), null, 'scott');
        }
        $crawler = $this->page(['area' => ['operations'], 'per_page' => '25', 'page' => '2']);
        self::assertSame('Page 2 of 3', $crawler->filter('#audit-entries-title + span')->text());
        self::assertSame('/dashboard/audit-trail?'.http_build_query(['area' => ['operations'], 'per_page' => '25', 'page' => 3]), $crawler->filter('.pagination-next')->attr('href'));
        self::assertSame('page', $crawler->filter('.pagination-link.is-current')->attr('aria-current'));
    }

    public function testUnusableParametersAreExplainedAndTheRestStillApplies(): void
    {
        $crawler = $this->page(['from' => 'last week', 'area' => ['users', 'nonsense']]);

        $problems = $crawler->filter('.notification.is-warning')->each(static fn (Crawler $notice): string => $notice->text());
        self::assertSame(['The start date must be a date like 2026-10-18.', 'An unknown area was left out of the filter.'], $problems);
        self::assertSame('2 entries match', $crawler->filter('#audit-entries-title')->text());
    }

    public function testADatabaseProblemIsShownAndTheSwitchesStillWork(): void
    {
        $this->connection->executeStatement('DROP TABLE audit_trail');
        $crawler = $this->page([]);

        self::assertStringContainsString('could not be read', $crawler->filter('.notification.is-danger')->text());
        self::assertCount(0, $crawler->filter('.audit-trail-entries'));
        self::assertCount(count(AuditTrailSettings::AREAS), $crawler->filter('#audit-switches input[type="checkbox"]'));
        self::assertNull($crawler->filter('#audit-area-sign_ins')->attr('checked'));
        self::assertSame('checked', $crawler->filter('#audit-area-settings')->attr('checked'));
    }

    public function testOnlyAdministratorsSeeOrChangeTheAuditTrail(): void
    {
        foreach (['index' => Request::create('/dashboard/audit-trail'), 'export' => Request::create('/dashboard/audit-trail/export'), 'saveSettings' => $this->settingsRequest([])] as $action => $request) {
            try {
                $this->controller($request, false)->{$action}($request);
                self::fail($action.' was allowed.');
            } catch (AccessDeniedException) {
            }
        }
        self::assertArrayNotHasKey(AuditTrailSettings::KEY, Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testTheExportHasTheFilteredEntriesAndCannotRunFormulas(): void
    {
        $request = Request::create('/dashboard/audit-trail/export', 'GET', ['q' => 'hyperlink']);
        $response = $this->controller($request)->export($request);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="audit-trail-\d{8}-\d{6}\.csv"$/', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('Filtered by text.', $request->attributes->get(AdminAuditSubscriber::DETAILS), 'the search itself is not recorded');
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", substr($csv, 3))));
        self::assertSame(['Time (UTC)', 'Area', 'Action', 'Outcome', 'User', 'Subject', 'Details', 'Job run', 'Entry'], $rows[0]);
        self::assertCount(2, $rows);
        self::assertSame(['2026-10-03 00:00:00', 'Background job runs', 'BigQuery sync', 'Failed', '', 'bi_anonymous_events_v1'], array_slice($rows[1], 0, 6));
        self::assertStringStartsWith("'=HYPERLINK", $rows[1][6]);
        self::assertSame('41', $rows[1][7]);

        $everything = Request::create('/dashboard/audit-trail/export');
        $this->controller($everything)->export($everything);
        self::assertSame('All entries.', $everything->attributes->get(AdminAuditSubscriber::DETAILS));
    }

    public function testAnExportThatCannotReadTheDatabaseReturnsToThePage(): void
    {
        $this->connection->executeStatement('DROP TABLE audit_trail');
        $request = Request::create('/dashboard/audit-trail/export', 'GET', ['area' => ['users']]);
        $response = $this->controller($request)->export($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/dashboard/audit-trail?'.http_build_query(['area' => ['users']]), $response->headers->get('Location'));
        self::assertNotEmpty($this->session->getFlashBag()->peek('error'));
    }

    public function testTheSwitchesAreSavedAndTheChangeIsSummarized(): void
    {
        $request = $this->settingsRequest(['sign_ins' => '1', 'operations' => '0']);
        $response = $this->controller($request)->saveSettings($request);

        self::assertSame('/dashboard/audit-trail#audit-switches', $response->headers->get('Location'));
        self::assertSame(['Saved what the audit trail records. Changes to these switches are always recorded.'], $this->session->getFlashBag()->get('success'));
        $saved = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame(['operations' => false, 'sign_ins' => true], $saved[AuditTrailSettings::KEY], 'only the changed switches are written');
        self::assertSame('Kept', $saved['brand_name']);
        self::assertSame('Turned on: sign_ins. Turned off: operations.', $request->attributes->get(AdminAuditSubscriber::DETAILS));

        $again = $this->settingsRequest(['sign_ins' => '1', 'operations' => '0']);
        $this->controller($again)->saveSettings($again);
        self::assertSame('No area changed.', $again->attributes->get(AdminAuditSubscriber::DETAILS));
    }

    public function testUntickingEveryAreaTurnsThemAllOff(): void
    {
        $request = Request::create('/dashboard/audit-trail/settings', 'POST', ['_csrf_token' => 'valid-token']);
        $this->controller($request)->saveSettings($request);

        self::assertSame(array_fill_keys(array_keys(AuditTrailSettings::AREAS), false), (new AuditTrailSettings(new AggregateConfigLoader($this->projectDir, 'test')))->all());
        self::assertStringStartsWith('Turned off: settings, users,', (string) $request->attributes->get(AdminAuditSubscriber::DETAILS));
    }

    public function testMalformedSwitchFormsSaveNothing(): void
    {
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $areas = array_fill_keys(array_keys(AuditTrailSettings::AREAS), '1');
        foreach ([
            ['_csrf_token' => 'forged', 'record' => $areas],
            ['_csrf_token' => 'valid-token', 'record' => 'settings'],
            ['_csrf_token' => 'valid-token', 'record' => $areas + ['everything' => '1']],
            ['_csrf_token' => 'valid-token', 'record' => array_replace($areas, ['users' => '0'])],
            ['_csrf_token' => 'valid-token', 'record' => array_replace($areas, ['users' => ['1']])],
            ['_csrf_token' => 'valid-token', 'record' => $areas, 'extra' => '1'],
        ] as $form) {
            $request = Request::create('/dashboard/audit-trail/settings', 'POST', $form);
            $this->controller($request)->saveSettings($request);
            self::assertNotEmpty($this->session->getFlashBag()->get('error'), json_encode($form));
        }
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testCellsThatASpreadsheetWouldRunAreQuoted(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx"] as $value) {
            self::assertSame("'".$value, AuditTrailController::csvCell($value));
        }
        self::assertSame('Changed aggregate.yaml: brand_name', AuditTrailController::csvCell('Changed aggregate.yaml: brand_name'));
        self::assertSame('', AuditTrailController::csvCell(''));
    }

    /** @param array<string, mixed> $query */
    private function page(array $query): Crawler
    {
        $request = Request::create('/dashboard/audit-trail', 'GET', $query);
        $response = $this->controller($request)->index($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return new Crawler((string) $response->getContent());
    }

    /** @param array<string, string> $changes */
    private function settingsRequest(array $changes): Request
    {
        // As a browser sends the form: only the ticked boxes.
        $ticked = array_filter(array_replace(array_map(static fn (bool $recorded): string => $recorded ? '1' : '0', AuditTrailSettings::defaults()), $changes), static fn (string $value): bool => $value === '1');

        return Request::create('/dashboard/audit-trail/settings', 'POST', ['_csrf_token' => 'valid-token'] + ($ticked === [] ? [] : ['record' => $ticked]));
    }

    private function controller(Request $request, bool $admin = true): AuditTrailController
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $controller = new AuditTrailController(
            $config,
            new AuditTrailLog($this->connection),
            new AuditTrailSettings($config),
            new AnalyticsDataLifecyclePolicy($config),
            new NullLogger(),
        );
        $request->setSession($this->session);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $role): bool => $admin && ($role === 'ROLE_ADMIN' || str_starts_with($role, 'DASHBOARD_')));
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getId() === AuditTrailController::CSRF_TOKEN_ID && $token->getValue() === 'valid-token');
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(self::url(...));
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
            new ArrayLoader(['base.html.twig' => '{% block body %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Aggregate']);
        $twig->addFunction(new TwigFunction('path', self::url(...)));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => $id === AuditTrailController::CSRF_TOKEN_ID ? 'valid-token' : 'wrong-token'));
        TwigComponents::register($twig);

        return $twig;
    }

    /** @param array<string, mixed> $parameters */
    private static function url(string $name, array $parameters = []): string
    {
        $fragment = isset($parameters['_fragment']) ? '#'.$parameters['_fragment'] : '';
        unset($parameters['_fragment']);
        $path = match ($name) {
            'app_audit_trail' => '/dashboard/audit-trail',
            'app_audit_trail_export' => '/dashboard/audit-trail/export',
            'app_audit_trail_settings' => '/dashboard/audit-trail/settings',
            'app_data_lifecycle' => '/dashboard/data-lifecycle',
        };

        return $path.($parameters !== [] ? '?'.http_build_query($parameters) : '').$fragment;
    }
}
