<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\BigQueryController;
use App\Service\AggregateConfigLoader;
use App\Service\BigQuery\BigQueryBackgroundSync;
use App\Service\BigQuery\BigQueryException;
use App\Service\BigQuery\BigQuerySecrets;
use App\Service\BigQuery\BigQuerySettings;
use App\Service\BigQuery\BigQuerySyncRunner;
use App\Service\BigQuery\GoogleSignIn;
use App\Tests\Service\BigQuery\GoogleCredentialsTest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

final class BigQueryControllerTest extends TestCase
{
    private string $projectDir;
    private array $environment;
    private BigQuerySyncRunner $runner;
    private BigQueryBackgroundSync $background;
    private array $httpRequests = [];
    private Session $session;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach ([...array_keys(BigQuerySettings::defaults()), BigQuerySecrets::CLIENT_SECRET_ENV, 'dashboard_enabled'] as $key) {
            unset($_ENV[strtoupper($key)], $_SERVER[strtoupper($key)]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-bigquery-controller-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->writeYaml(['admin_token' => 'kept', 'app_host' => 'https://analytics.example.com']);
        $this->runner = $this->createMock(BigQuerySyncRunner::class);
        $this->runner->method('status')->willReturnCallback(static fn (array $settings): array => [
            'runner' => null,
            'views' => array_map(static fn (string $view): array => [
                'view' => $view, 'private' => str_starts_with($view, 'analytics_'), 'description' => '', 'status' => 'never',
                'started_at' => null, 'finished_at' => null, 'succeeded_at' => null, 'row_count' => null, 'message' => null, 'job_id' => null, 'next_due' => null,
            ], BigQuerySettings::selectedViews($settings)),
            'scheduler_late' => $settings['bigquery_enabled'],
        ]);
        $this->background = $this->createMock(BigQueryBackgroundSync::class);
        $this->background->method('cronLine')->willReturn('*/5 * * * * cd /srv/aggregate && php bin/console app:bigquery:sync --no-interaction');
        $this->session = new Session(new MockArraySessionStorage());
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        foreach ([...(glob($this->projectDir.'/config/secrets/*') ?: []), ...(glob($this->projectDir.'/config/*') ?: [])] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->projectDir.'/config/secrets');
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testThePageShowsTheThreeStepsWithSafeDefaults(): void
    {
        $crawler = new Crawler((string) $this->page()->getContent());

        self::assertSame('BigQuery sync', $crawler->filter('h1')->text());
        self::assertCount(3, $crawler->filter('input[name="auth"]'));
        self::assertSame('service_account', $crawler->filter('input[name="auth"][checked]')->attr('value'));
        self::assertCount(12, $crawler->filter('input[name="views[]"][checked]'));
        self::assertCount(6, $crawler->filter('input[name="private_views[]"]'));
        self::assertCount(0, $crawler->filter('input[name="private_views[]"][checked]'));
        self::assertSame('https://analytics.example.com/dashboard/bigquery/google/callback', $crawler->filter('#bigquery-redirect-uri')->attr('value'));
        self::assertStringContainsString('No key is installed yet.', $crawler->text());
        self::assertSame('disabled', $crawler->filter('button[form="bigquery-sync"]')->attr('disabled'));
        self::assertStringContainsString('app:bigquery:sync --no-interaction', $crawler->filter('pre code')->text());
        self::assertSame('false', $crawler->filter('[data-controller="pages--bigquery--index"]')->attr('data-pages--bigquery--index-running-value'));
        // Google's sign-in page must open as a normal navigation, not a Turbo fetch.
        self::assertSame('false', $crawler->filter('form#bigquery-google-start')->attr('data-turbo'));
    }

    public function testAccessNeedsAnAdministratorAndTheDashboard(): void
    {
        try {
            $request = Request::create('/dashboard/bigquery');
            $this->controller($request, admin: false)->index($request);
            self::fail('A non-admin opened the page.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        $_ENV['DASHBOARD_ENABLED'] = 'false';
        $this->expectException(NotFoundHttpException::class);
        $this->page();
    }

    public function testEveryActionIsUnavailableWhenHeadless(): void
    {
        $_ENV['DASHBOARD_ENABLED'] = 'false';
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        foreach (['status', 'saveConnection', 'saveViews', 'saveSchedule', 'removeKey', 'test', 'syncNow', 'googleStart', 'googleCallback', 'googleDisconnect'] as $action) {
            $request = $this->post(['dataset' => 'headless']);
            try {
                $action === 'status' ? $this->controller($request)->status() : $this->controller($request)->$action($request);
                self::fail($action.' was available without the dashboard.');
            } catch (NotFoundHttpException) {
                self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
            }
        }
    }

    public function testAValidKeyAndConnectionAreSavedTogether(): void
    {
        $request = $this->post(['auth' => 'service_account', 'project' => '', 'dataset' => 'analytics', 'location' => 'eu', 'service_account_key_json' => GoogleCredentialsTest::keyJson()]);
        $this->controller($request)->saveConnection($request);

        self::assertSame([], $this->session->getFlashBag()->peek('error'));
        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('kept', $yaml['admin_token']);
        self::assertSame('analytics', $yaml['bigquery_dataset']);
        self::assertSame('EU', $yaml['bigquery_location']);
        self::assertSame(0600, fileperms($this->projectDir.'/config/secrets/bigquery-service-account.json') & 0777);
        self::assertStringContainsString('aggregate-sync@my-analytics-123.iam.gserviceaccount.com', implode(' ', $this->session->getFlashBag()->get('success')));

        $crawler = new Crawler((string) $this->page()->getContent());
        self::assertStringContainsString('Key installed for aggregate-sync@my-analytics-123.iam.gserviceaccount.com', $crawler->text());
        self::assertSame('my-analytics-123', $crawler->filter('#bigquery-project')->attr('placeholder'));
    }

    public function testUploadedKeyFilesAreAcceptedAndInvalidInputSavesNothing(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'key');
        file_put_contents($file, GoogleCredentialsTest::keyJson());
        $request = $this->post(['auth' => 'service_account', 'dataset' => 'aggregate', 'location' => 'US']);
        $request->files->set('service_account_key_file', new UploadedFile($file, 'key.json', 'application/json', null, true));
        $this->controller($request)->saveConnection($request);
        self::assertFileExists($this->projectDir.'/config/secrets/bigquery-service-account.json');
        unlink($this->projectDir.'/config/secrets/bigquery-service-account.json');

        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        foreach ([
            ['dataset' => 'bad-name', 'service_account_key_json' => GoogleCredentialsTest::keyJson()],
            ['dataset' => 'fine', 'service_account_key_json' => '{"type":"external_account"}'],
            ['dataset' => 'fine', 'admin_token' => 'replaced'],
        ] as $values) {
            $request = $this->post(['auth' => 'service_account', 'location' => 'US', ...$values]);
            $this->controller($request)->saveConnection($request);
            self::assertNotSame([], $this->session->getFlashBag()->get('error'));
            self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
            self::assertFileDoesNotExist($this->projectDir.'/config/secrets/bigquery-service-account.json');
        }

        $forged = Request::create('/dashboard/bigquery/connection', 'POST', ['_csrf_token' => 'forged', 'dataset' => 'forged']);
        $forged->setSession($this->session);
        $this->controller($forged)->saveConnection($forged);
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testPrivateViewsNeedAConfirmationWhenAdded(): void
    {
        $before = file_get_contents($this->projectDir.'/config/aggregate.yaml');
        $request = $this->post(['views' => ['bi_anonymous_events_v1'], 'private_views' => ['analytics_custom_events_v1']]);
        $this->controller($request)->saveViews($request);
        self::assertStringContainsString('Confirm that the private views', implode(' ', $this->session->getFlashBag()->get('error')));
        self::assertSame($before, file_get_contents($this->projectDir.'/config/aggregate.yaml'));

        $request = $this->post(['views' => ['bi_anonymous_events_v1'], 'private_views' => ['analytics_custom_events_v1'], 'private_views_confirmed' => '1']);
        $this->controller($request)->saveViews($request);
        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame(['bi_anonymous_events_v1'], $yaml['bigquery_views']);
        self::assertSame(['analytics_custom_events_v1'], $yaml['bigquery_private_views']);

        // Keeping or removing a confirmed view needs no new confirmation.
        $request = $this->post(['views' => [], 'private_views' => ['analytics_custom_events_v1']]);
        $this->controller($request)->saveViews($request);
        self::assertSame([], $this->session->getFlashBag()->get('error'));
        $request = $this->post(['views' => ['bi_dim_device_class_v1']]);
        $this->controller($request)->saveViews($request);
        self::assertSame([], Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['bigquery_private_views']);

        // A private view cannot be smuggled into the approved list.
        $request = $this->post(['views' => ['analytics_custom_goals_v1'], 'private_views_confirmed' => '1']);
        $this->controller($request)->saveViews($request);
        self::assertStringContainsString('bigquery_private_views', implode(' ', $this->session->getFlashBag()->get('error')));
    }

    public function testScheduleSavesAndEnvironmentControlledValuesStay(): void
    {
        $_ENV['BIGQUERY_INTERVAL_MINUTES'] = '360';
        $request = $this->post(['enabled' => '1', 'interval' => '15']);
        $this->controller($request)->saveSchedule($request);

        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertTrue($yaml['bigquery_enabled']);
        self::assertArrayNotHasKey('bigquery_interval_minutes', $yaml);
        $crawler = new Crawler((string) $this->page()->getContent());
        self::assertSame('disabled', $crawler->filter('#bigquery-interval')->attr('disabled'));
        self::assertStringContainsString('BIGQUERY_INTERVAL_MINUTES', $crawler->text());
        self::assertStringContainsString('The scheduled sync is not running.', $crawler->text());
    }

    public function testSyncNowStartsTheBackgroundCommandOnlyWhenSyncIsOn(): void
    {
        $this->background->expects(self::once())->method('start');
        $request = $this->post();
        $this->controller($request)->syncNow($request);
        self::assertStringContainsString('Turn BigQuery sync on', implode(' ', $this->session->getFlashBag()->get('error')));

        $this->writeYaml(['bigquery_enabled' => true]);
        $request = $this->post();
        $this->controller($request)->syncNow($request);
        self::assertStringContainsString('The sync has started', implode(' ', $this->session->getFlashBag()->get('success')));

        // The page waits for the background run, without offering a second one.
        $crawler = new Crawler((string) $this->page()->getContent());
        $page = $crawler->filter('[data-controller="pages--bigquery--index"]');
        self::assertSame('true', $page->attr('data-pages--bigquery--index-running-value'));
        self::assertGreaterThan(time() - 60, (int) $page->attr('data-pages--bigquery--index-since-value'));
        self::assertSame('disabled', $crawler->filter('button[form="bigquery-sync"]')->attr('disabled'));
    }

    public function testTestConnectionReportsTheAccountOrTheProblem(): void
    {
        $this->runner->method('check')->willReturnOnConsecutiveCalls(
            ['identity' => 'sync@p.iam.gserviceaccount.com', 'project' => 'my-project', 'dataset' => 'aggregate', 'dataset_exists' => false, 'dataset_location' => '', 'location' => 'US'],
            self::throwException(new BigQueryException('BigQuery refused the request: denied.')),
        );
        $request = $this->post();
        $this->controller($request)->test($request);
        self::assertSame(['Connected as sync@p.iam.gserviceaccount.com. Project my-project can run BigQuery jobs. Dataset aggregate does not exist yet; the first sync creates it in US.'], $this->session->getFlashBag()->get('success'));

        $this->controller($request)->test($request);
        self::assertSame(['The connection test failed: BigQuery refused the request: denied.'], $this->session->getFlashBag()->get('error'));
    }

    public function testGoogleSignInCompletesOnlyForTheSessionThatStartedIt(): void
    {
        $request = $this->post();
        $this->controller($request)->googleStart($request);
        self::assertStringContainsString('Save the OAuth client ID and secret first', implode(' ', $this->session->getFlashBag()->get('error')));

        $request = $this->post(['auth' => 'google_sign_in', 'dataset' => 'aggregate', 'location' => 'US', 'oauth_client_id' => '123-abc.apps.googleusercontent.com', 'oauth_client_secret' => 'client-secret']);
        $this->controller($request)->saveConnection($request);
        $this->session->getFlashBag()->clear();
        $request = $this->post();
        $redirect = $this->controller($request)->googleStart($request);
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', (string) $redirect->headers->get('Location'));
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertSame('https://analytics.example.com/dashboard/bigquery/google/callback', $query['redirect_uri']);

        $wrong = $this->get(['state' => 'forged', 'code' => 'code']);
        $this->controller($wrong)->googleCallback($wrong);
        self::assertStringContainsString('could not be matched', implode(' ', $this->session->getFlashBag()->get('error')));
        self::assertNull((new BigQuerySecrets($this->settings(), $this->projectDir))->refreshToken());

        // The state is single use: start again, then return from Google.
        $request = $this->post();
        $redirect = $this->controller($request)->googleStart($request);
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $callback = $this->get(['state' => $query['state'], 'code' => 'auth-code']);
        $this->controller($callback)->googleCallback($callback);

        self::assertSame([], $this->session->getFlashBag()->get('error'));
        self::assertSame(['Signed in with Google as admin@example.com. BigQuery sync now acts as this account.'], $this->session->getFlashBag()->get('success'));
        $secrets = new BigQuerySecrets($this->settings(), $this->projectDir);
        self::assertSame('refresh-token', $secrets->refreshToken());
        self::assertSame('admin@example.com', $secrets->signInConnection()['account']);
        parse_str($this->httpRequests[0][2], $exchange);
        self::assertSame('auth-code', $exchange['code']);
        self::assertSame('client-secret', $exchange['client_secret']);
        self::assertSame('https://analytics.example.com/dashboard/bigquery/google/callback', $exchange['redirect_uri']);

        $replayed = $this->get(['state' => $query['state'], 'code' => 'auth-code']);
        $this->controller($replayed)->googleCallback($replayed);
        self::assertStringContainsString('could not be matched', implode(' ', $this->session->getFlashBag()->get('error')));

        $request = $this->post();
        $this->controller($request)->googleDisconnect($request);
        self::assertNull((new BigQuerySecrets($this->settings(), $this->projectDir))->refreshToken());
        self::assertStringEndsWith('/revoke', $this->httpRequests[array_key_last($this->httpRequests)][1]);
    }

    public function testCancelledGoogleSignInChangesNothing(): void
    {
        $this->session->set('bigquery_google_sign_in', ['state' => 'state-1', 'verifier' => 'v', 'client_id' => 'c.apps.googleusercontent.com', 'expires' => time() + 600]);
        $callback = $this->get(['state' => 'state-1', 'error' => 'access_denied']);
        $this->controller($callback)->googleCallback($callback);

        self::assertSame(['Google sign-in was cancelled.'], $this->session->getFlashBag()->get('error'));
        self::assertSame([], $this->httpRequests);
    }

    public function testStatusEndpointReportsWhetherASyncIsRunning(): void
    {
        $body = json_decode((string) $this->controller()->status()->getContent(), true);
        self::assertFalse($body['running']);
        self::assertNull($body['last_run']);
        self::assertCount(12, $body['views']);
    }

    private function page(): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('/dashboard/bigquery');

        return $this->controller($request)->index($request);
    }

    private function writeYaml(array $values): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['admin_token' => 'kept', 'app_host' => 'https://analytics.example.com', ...$values], 4, 2));
    }

    private function settings(): BigQuerySettings
    {
        return new BigQuerySettings(new AggregateConfigLoader($this->projectDir, 'test'), $this->projectDir);
    }

    private function post(array $values = []): Request
    {
        $request = Request::create('/dashboard/bigquery', 'POST', ['_csrf_token' => 'valid-token', ...$values]);
        $request->setSession($this->session);

        return $request;
    }

    private function get(array $query): Request
    {
        $request = Request::create('/dashboard/bigquery/google/callback', 'GET', $query);
        $request->setSession($this->session);

        return $request;
    }

    private function controller(?Request $request = null, bool $admin = true): BigQueryController
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $settings = new BigQuerySettings($config, $this->projectDir);
        $idToken = 'h.'.rtrim(strtr(base64_encode(json_encode(['email' => 'admin@example.com'])), '+/', '-_'), '=').'.s';
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($idToken): MockResponse {
            $this->httpRequests[] = [$method, $url, $options['body'] ?? ''];

            return str_ends_with($url, '/revoke')
                ? new MockResponse('', ['http_code' => 200])
                : new MockResponse(json_encode(['access_token' => 'a', 'refresh_token' => 'refresh-token', 'scope' => 'https://www.googleapis.com/auth/bigquery openid', 'id_token' => $idToken]), ['http_code' => 200]);
        });
        $controller = new BigQueryController($config, $settings, new BigQuerySecrets($settings, $this->projectDir), $this->runner, $this->background, new GoogleSignIn($http), new NullLogger());
        $request ??= Request::create('/dashboard/bigquery');
        $request->setSession($this->session);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $role): bool => $admin && $role === 'ROLE_ADMIN');
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getId() === BigQueryController::CSRF_TOKEN_ID && $token->getValue() === 'valid-token');
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
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => $id === BigQueryController::CSRF_TOKEN_ID ? 'valid-token' : 'wrong-token'));
        \App\Tests\Support\TwigComponents::register($twig);

        return $twig;
    }

    private static function url(string $name, array $parameters = []): string
    {
        return '/dashboard/bigquery'.match ($name) {
            'app_bigquery' => '',
            'app_bigquery_status' => '/status',
            'app_bigquery_connection' => '/connection',
            'app_bigquery_views' => '/views',
            'app_bigquery_schedule' => '/schedule',
            'app_bigquery_key_remove' => '/key/remove',
            'app_bigquery_test' => '/test',
            'app_bigquery_sync' => '/sync',
            'app_bigquery_google_start' => '/google/start',
            'app_bigquery_google_callback' => '/google/callback',
            'app_bigquery_google_disconnect' => '/google/disconnect',
        }.($parameters === [] ? '' : '?'.http_build_query($parameters));
    }
}
