<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AlertsController;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Service\AggregateConfigLoader;
use App\Service\Alerts\AlertEvaluator;
use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSecrets;
use App\Service\Alerts\AlertSettings;
use App\Service\Alerts\WebhookSender;
use App\Service\AppBranding;
use App\Service\DocumentationLinks;
use App\Service\Email\EmailProviders;
use App\Service\Email\EmailSecrets;
use App\Service\Email\EmailSettings;
use App\Service\Email\Provider\PostmarkProvider;
use App\Service\Email\TransactionalMailer;
use App\Service\FeatureFlags;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Service\ReportingTimezone;
use App\Service\Scheduler\ScheduledJob;
use App\Service\Scheduler\Scheduler;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use App\Tests\Service\Alerts\AlertsTestDatabase;
use App\Tests\Service\Email\EmailTestKit;
use App\Tests\Service\Operations\OperationsDatabase;
use App\Tests\Support\TwigComponents;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
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

final class AlertsControllerTest extends TestCase
{
    private const TOKEN_A = '019c7e21-89ab-7cde-8f01-23456789abcd';

    private string $projectDir;
    private Connection $connection;
    private MockClock $clock;
    /** @var list<string> */
    private array $emailedRecipients = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-alerts-controller-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        mkdir($this->projectDir.'/var', 0700, true);
        $this->connection = AlertsTestDatabase::connection();
        $this->clock = new MockClock('2025-06-02 14:05:00 UTC');

        file_put_contents($this->projectDir.'/config/websites.yaml', Yaml::dump([
            'websites' => [
                ['name' => 'Geektown USA', 'token' => self::TOKEN_A, 'domain' => 'some.domain.com'],
            ],
        ], 6, 2));
        (new EmailSecrets($this->projectDir))->set((new PostmarkProvider())->credential(), 'postmark-token-123');
        $this->writeConfig();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testStandardUsersAreDeniedAccessToAlertsPage(): void
    {
        $this->expectException(AccessDeniedException::class);
        $request = $this->request('/dashboard/alerts');
        $this->controller($request, admin: false)->index($request);
    }

    public function testWhenFeatureFlagIsOffIndexExplainsHowToTurnItOnAndMutationRoutesReturn404(): void
    {
        $this->writeConfig(['feature_flags' => ['alerts' => ['enabled' => false, 'hide_from_navigation' => true]]]);

        $request = $this->request('/dashboard/alerts');
        $response = $this->controller($request)->index($request);
        self::assertSame(200, $response->getStatusCode());
        $crawler = new Crawler((string) $response->getContent());
        self::assertStringContainsString('Alerts are off', $crawler->text());
        self::assertCount(1, $crawler->filter('a[href="/dashboard/feature-flags"]'));

        $this->expectException(NotFoundHttpException::class);
        $this->controller($request)->newRule();
    }

    public function testGenerateSecretShowsPlaintextOnceWithCopyButtonAndNeverOnReloadAndTargetsRemainRedacted(): void
    {
        // Configure a webhook destination and an email destination.
        $this->writeConfig([
            'alerts' => [
                'destinations' => [
                    [
                        'id' => 'geektown-hub',
                        'label' => 'Geektown Hub',
                        'type' => 'webhook',
                        'url' => 'https://hooks.geektown.example/alerts/secret-endpoint?token=xyz',
                    ],
                    [
                        'id' => 'ops-email',
                        'label' => 'Ops email',
                        'type' => 'email',
                        'recipients' => ['ops@example.com', 'oncall@example.com'],
                    ],
                ],
                'rules' => [
                    [
                        'id' => 'geektown-weekly',
                        'enabled' => true,
                        'covers' => 'website:'.self::TOKEN_A,
                        'source' => 'events',
                        'condition' => 'fewer_than',
                        'threshold' => 5,
                        'window' => 'week',
                        'destinations' => ['geektown-hub', 'ops-email'],
                    ],
                ],
            ],
        ]);

        // Generate a webhook signing secret: shown once in the response.
        $postReq = $this->request('/dashboard/alerts/secret', 'POST', [
            '_csrf_token' => 'valid-token',
            'secret_action' => 'generate',
        ]);
        $genResponse = $this->controller($postReq)->saveSecret($postReq);
        self::assertSame(200, $genResponse->getStatusCode());
        $genHtml = (string) $genResponse->getContent();
        $savedSecret = (new AlertSecrets($this->projectDir))->get();
        self::assertNotNull($savedSecret);
        self::assertStringContainsString('Shown once. Copy it now.', $genHtml);
        self::assertStringContainsString($savedSecret, $genHtml);

        // Reload GET /dashboard/alerts: secret, full webhook URL, and email addresses are NOT in the HTML!
        $getReq = $this->request('/dashboard/alerts');
        $indexResponse = $this->controller($getReq)->index($getReq);
        $indexHtml = (string) $indexResponse->getContent();
        self::assertStringNotContainsString($savedSecret, $indexHtml, 'Secret is never shown again on reload.');
        self::assertStringNotContainsString('secret-endpoint', $indexHtml, 'Full webhook path is never shown.');
        self::assertStringNotContainsString('ops@example.com', $indexHtml, 'Recipient email address is never shown.');
        self::assertStringNotContainsString('oncall@example.com', $indexHtml);
        self::assertStringContainsString('hooks.geektown.example', $indexHtml, 'Webhook host is shown.');
        self::assertStringContainsString('2 listed recipients', $indexHtml, 'Recipient count is shown.');
        self::assertStringContainsString('Scheduled task is late', $indexHtml, 'Warns when scheduler has not run recently.');
    }

    public function testTestConfirmationAndSendTestToSpecificListedRecipientWithoutChangingRuleStatus(): void
    {
        (new AlertSecrets($this->projectDir))->set('secret-123');
        $this->writeConfig([
            'alerts' => [
                'destinations' => [
                    [
                        'id' => 'ops-email',
                        'label' => 'Ops email',
                        'type' => 'email',
                        'recipients' => ['first@example.com', 'second@example.com'],
                    ],
                ],
                'rules' => [
                    [
                        'id' => 'geektown-weekly',
                        'enabled' => true,
                        'covers' => 'website:'.self::TOKEN_A,
                        'source' => 'events',
                        'condition' => 'fewer_than',
                        'threshold' => 5,
                        'window' => 'week',
                        'destinations' => ['ops-email'],
                    ],
                ],
            ],
        ]);

        // Open test confirmation step for ops-email.
        $confirmReq = $this->request('/dashboard/alerts', 'GET', [], ['test' => 'ops-email']);
        $confirmHtml = (string) $this->controller($confirmReq)->index($confirmReq)->getContent();
        self::assertStringContainsString('This sends a test to', $confirmHtml);
        self::assertStringContainsString('All listed recipients (2)', $confirmHtml);
        self::assertStringNotContainsString('first@example.com', $confirmHtml, 'Confirmation options mask the local part.');

        // Submit test to recipient_index = 1 (second@example.com).
        $testReq = $this->request('/dashboard/alerts/test', 'POST', [
            '_csrf_token' => 'valid-token',
            'destination' => 'ops-email',
            'recipient_index' => '1',
        ]);
        $testResponse = $this->controller($testReq)->test($testReq);
        self::assertSame(200, $testResponse->getStatusCode());
        $testHtml = (string) $testResponse->getContent();
        self::assertStringContainsString('Test sent to Ops email', $testHtml);
        self::assertSame(['second@example.com'], $this->emailedRecipients);
        self::assertStringNotContainsString('second@example.com', $testHtml);
        self::assertStringContainsString('Waiting for the first full hour', $testHtml, 'Rule status remains unchanged.');
    }

    public function testRuleFormShowsReadonlyIdOnEditAndRejectsInvalidRuleWithValidatorMessage(): void
    {
        $this->writeConfig([
            'reporting_timezone' => 'America/New_York',
            'alerts' => [
                'destinations' => [
                    [
                        'id' => 'geektown-hub',
                        'label' => 'Geektown Hub',
                        'type' => 'webhook',
                        'url' => 'https://hooks.geektown.example/alerts/ingest',
                    ],
                ],
                'rules' => [
                    [
                        'id' => 'geektown-weekly',
                        'enabled' => true,
                        'covers' => 'website:'.self::TOKEN_A,
                        'source' => 'events',
                        'privacy_mode' => 'enhanced',
                        'condition' => 'fewer_than',
                        'threshold' => 5,
                        'window' => 'hour',
                        'destinations' => ['geektown-hub'],
                    ],
                ],
            ],
        ]);

        $req = $this->request('/dashboard/alerts/rules/geektown-weekly/edit');
        $editResponse = $this->controller($req)->editRule('geektown-weekly');
        $crawler = new Crawler((string) $editResponse->getContent());

        self::assertSame('readonly', $crawler->filter('#rule-id')->attr('readonly'));
        self::assertStringContainsString('To change this ID, delete the rule and add a new one.', $crawler->text());
        self::assertStringContainsString('These are UTC hours, not your reporting time zone (America/New_York).', $crawler->text());
        self::assertStringContainsString('Saving updates config/aggregate.yaml. Hand-written YAML comments are not preserved.', $crawler->text());

        // Submitting an invalid rule returns 422 with a role="alert" summary using the validator message.
        $invalidPost = $this->request('/dashboard/alerts/rules/save', 'POST', [
            '_csrf_token' => 'valid-token',
            '_editing' => '1',
            'id' => 'geektown-weekly',
            'covers' => 'website:'.self::TOKEN_A,
            'source' => 'events',
            'condition' => 'fewer_than',
            'threshold' => '0',
            'window' => 'day',
            'destinations' => ['geektown-hub'],
        ]);
        $invalidResp = $this->controller($invalidPost)->saveRule($invalidPost);
        self::assertSame(422, $invalidResp->getStatusCode());
        $errCrawler = new Crawler((string) $invalidResp->getContent());
        self::assertCount(1, $errCrawler->filter('[role="alert"]'));
        self::assertStringContainsString('Threshold (N) must be between 1 and', $errCrawler->filter('[role="alert"]')->text());
    }

    /**
     * @param array<string, mixed> $submitted
     * @param array<string, mixed> $query
     */
    private function request(string $path, string $method = 'GET', array $submitted = [], array $query = []): Request
    {
        $request = Request::create($path, $method, $method === 'GET' ? $query : $submitted);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /** @param array<string, mixed> $extra */
    private function writeConfig(array $extra = []): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(array_replace([
            'brand_name' => 'Example Analytics',
            'app_host' => 'https://analytics.example.com',
            'email_enabled' => true,
            'email_provider' => 'postmark',
            'email_from_address' => 'no-reply@example.com',
            'feature_flags' => [
                'alerts' => ['enabled' => true, 'hide_from_navigation' => false],
            ],
        ], $extra), 8, 2));
    }

    private function controller(Request $request, bool $admin = true, bool $csrfValid = true): AlertsController
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $websites = new WebsiteConfigManager($this->projectDir);
        $scopes = new WebsiteScopeCatalog($this->projectDir, 'test');
        $validator = new AlertRuleValidator($websites, $scopes);
        $settings = new AlertSettings($config, $validator, $websites, $scopes);
        $secrets = new AlertSecrets($this->projectDir);
        $audit = new AuditTrail($this->connection);
        $tasks = new ProcessingTasks($this->connection, $audit);

        $eventsRepo = $this->createStub(EventRepository::class);
        $eventsRepo->method('countEventsUpTo')->willReturn(10);

        $webhookSender = new WebhookSender(
            new MockHttpClient(new MockResponse('{"ok":true}', ['http_code' => 202])),
            $secrets,
            $validator,
            new NullLogger(),
            $this->clock,
            publicAddressesOnly: false,
        );

        $twigForMail = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'), ['strict_variables' => true, 'autoescape' => 'name']);
        $emailHttp = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
            $this->emailedRecipients[] = (string) $body['To'];

            return new MockResponse((string) json_encode(['MessageID' => 'pm-1']), ['http_code' => 200]);
        });
        $mailer = new TransactionalMailer(
            new EmailSettings($config, EmailProviders::builtIn()),
            new EmailSecrets($this->projectDir),
            $tasks,
            $twigForMail,
            new AppBranding($config, $this->projectDir),
            $config,
            new NullLogger(),
            EmailTestKit::transports($emailHttp),
            $this->clock,
        );

        $features = new FeatureFlags($config);
        $evaluator = new AlertEvaluator(
            $settings,
            $eventsRepo,
            $tasks,
            $audit,
            $this->connection,
            $webhookSender,
            $mailer,
            $config,
            $features,
            new NullLogger(),
            $this->clock,
        );

        $job = $this->createStub(ScheduledJob::class);
        $job->method('name')->willReturn('analytics_maintenance');
        $job->method('label')->willReturn('Analytics maintenance');
        $job->method('status')->willReturn([
            'enabled' => false,
            'schedule' => 'Daily',
            'last_run' => null,
            'last_status' => null,
            'next_due' => null,
            'problem' => null,
        ]);
        $scheduler = new Scheduler($job, $job, $job, $job, $this->projectDir, $this->clock, new NullLogger());

        $controller = new AlertsController(
            $config,
            $settings,
            $secrets,
            $evaluator,
            $mailer,
            $scheduler,
            $websites,
            $scopes,
            new ReportingTimezone($config),
            $features,
            new NullLogger(),
        );

        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn($admin);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(
            static fn (CsrfToken $t): bool => $csrfValid && $t->getValue() === 'valid-token',
        );
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'valid-token'));

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $params = []): string => match ($route) {
            'app_alerts' => '/dashboard/alerts'.($params !== [] ? '?'.http_build_query($params) : ''),
            'app_alerts_rule_new' => '/dashboard/alerts/rules/new',
            'app_alerts_rule_edit' => '/dashboard/alerts/rules/'.($params['id'] ?? '').'/edit',
            'app_alerts_rule_save' => '/dashboard/alerts/rules/save',
            'app_alerts_rule_delete_confirm', 'app_alerts_rule_delete' => '/dashboard/alerts/rules/'.($params['id'] ?? '').'/delete',
            'app_alerts_destination_new' => '/dashboard/alerts/destinations/new',
            'app_alerts_destination_edit' => '/dashboard/alerts/destinations/'.($params['id'] ?? '').'/edit',
            'app_alerts_destination_save' => '/dashboard/alerts/destinations/save',
            'app_alerts_destination_delete_confirm', 'app_alerts_destination_delete' => '/dashboard/alerts/destinations/'.($params['id'] ?? '').'/delete',
            'app_alerts_secret_save' => '/dashboard/alerts/secret',
            'app_alerts_test' => '/dashboard/alerts/test',
            'app_feature_flags' => '/dashboard/feature-flags',
            'app_scheduled_tasks' => '/dashboard/scheduled-tasks',
            'app_email' => '/dashboard/email',
            'app_audit_trail' => '/dashboard/audit-trail',
            default => '/'.$route,
        });

        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '<title>{% block title %}{% endblock %}</title>{% block body %}{% endblock %}']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $params = []): string => $router->generate($route, $params)));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'valid-token'));
        TwigComponents::register($twig);

        $user = (new User())->setUsername('scott');
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokens = $this->createStub(TokenStorageInterface::class);
        $tokens->method('getToken')->willReturn($token);

        $stack = new RequestStack();
        $stack->push($request);

        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('security.token_storage', $tokens);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }
}
