<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\EmailSettingsController;
use App\Entity\User;
use App\Security\SignInCode\SignInCodeSettings;
use App\Service\AggregateConfigLoader;
use App\Service\AppBranding;
use App\Service\Email\EmailProviders;
use App\Service\Email\EmailSecrets;
use App\Service\Email\EmailSettings;
use App\Service\Email\Provider\PostmarkProvider;
use App\Service\Email\TransactionalMailer;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\ProcessingTasks;
use App\Tests\Service\Email\EmailTestKit;
use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class EmailSettingsControllerTest extends TestCase
{
    private string $projectDir;
    private array $environment;
    private Connection $connection;
    private ?Session $session = null;
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['DASHBOARD_ENABLED', 'EMAIL_ENABLED', 'EMAIL_PROVIDER', 'EMAIL_FROM_ADDRESS', 'EMAIL_POSTMARK_SERVER_TOKEN', 'EMAIL_SENDGRID_API_KEY', 'SIGN_IN_CODES_ENABLED'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-email-page-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['brand_name' => 'Example Analytics']));
        $this->connection = OperationsDatabase::connection();
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testThePageOffersEveryServiceWhileEmailIsOff(): void
    {
        $crawler = $this->crawl($this->call('index', $this->request('GET', '/dashboard/email')));

        self::assertSame('Email - Example Analytics', $crawler->filter('title')->text());
        self::assertStringContainsString('Email is off', $crawler->filter('.email-status')->text());
        self::assertSame(['', 'postmark', 'sendgrid', 'mailgun', 'smtp', 'dsn'], $crawler->filter('select[name="email_provider"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertCount(5, $crawler->filter('.email-provider[data-provider]'));
        self::assertCount(1, $crawler->filter('input[name="credential[postmark_server_token]"][type="password"]'));
        self::assertCount(1, $crawler->filter('input[name="email_mailgun_domain"]'));
        self::assertCount(1, $crawler->filter('select[name="email_smtp_encryption"] option[value="starttls"][selected]'));
        self::assertStringContainsString('Add an email address to your profile', $crawler->filter('body')->text(), 'The signed-in administrator has no address yet');
    }

    public function testSavingWritesSettingsToYamlAndTheKeyToTheSecretsFileWithoutShowingItAgain(): void
    {
        $this->call('save', $this->request('POST', '/dashboard/email/save', [
            '_csrf_token' => 'valid',
            'email_enabled' => '1',
            'email_provider' => 'postmark',
            'email_from_address' => 'no-reply@example.com',
            'email_from_name' => '',
            'email_reply_to' => '',
            'email_postmark_message_stream' => 'outbound',
            'credential' => ['postmark_server_token' => ' secret-server-token ', 'sendgrid_api_key' => ''],
        ]));

        self::assertSame(['Email settings were saved.'], $this->session->getFlashBag()->get('success'));
        $yaml = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertTrue($yaml['email_enabled']);
        self::assertSame('postmark', $yaml['email_provider']);
        self::assertStringNotContainsString('secret-server-token', (string) file_get_contents($this->projectDir.'/config/aggregate.yaml'));
        self::assertSame(['postmark_server_token' => 'secret-server-token'], (new EmailSecrets($this->projectDir))->read());

        $page = $this->call('index', $this->request('GET', '/dashboard/email', [], 'scott@example.com'));
        $crawler = $this->crawl($page);
        self::assertStringNotContainsString('secret-server-token', (string) $page->getContent());
        self::assertSame('Saved; leave empty to keep it', $crawler->filter('input[name="credential[postmark_server_token]"]')->attr('placeholder'));
        self::assertCount(1, $crawler->filter('input[name="remove[postmark_server_token]"]'));
        self::assertStringContainsString('Email is on and sends through Postmark', $crawler->filter('.email-status')->text());
        self::assertCount(1, $crawler->filter('form[action="/dashboard/email/test"] button:not([disabled])'));

        $this->call('save', $this->request('POST', '/dashboard/email/save', ['_csrf_token' => 'valid', 'remove' => ['postmark_server_token' => '1']]));
        self::assertSame([], (new EmailSecrets($this->projectDir))->read());
        self::assertStringContainsString('The Postmark server api token is not set', implode(' ', $this->session->getFlashBag()->get('warning')));
    }

    public function testAnInvalidFormSavesNothing(): void
    {
        foreach ([
            [['email_enabled' => '1', 'email_provider' => 'mailgun', 'email_from_address' => 'a@example.com', 'credential' => ['mailgun_api_key' => 'k']], 'Enter the Mailgun sending domain'],
            [['email_provider' => 'postmark', 'credential' => ['unknown_key' => 'x']], 'unknown credential'],
            [['email_provider' => 'postmark', 'credential' => ['postmark_server_token' => "two\nlines"]], 'one line'],
            [['email_provider' => ['array']], 'unexpected setting'],
        ] as [$form, $message]) {
            $this->call('save', $this->request('POST', '/dashboard/email/save', ['_csrf_token' => 'valid', ...$form]));
            self::assertStringContainsString($message, implode(' ', $this->session->getFlashBag()->get('error')));
        }
        $this->call('save', $this->request('POST', '/dashboard/email/save', ['email_provider' => 'postmark']), false);
        self::assertSame(['Invalid security token. Please try again.'], $this->session->getFlashBag()->get('error'));

        self::assertSame(['brand_name' => 'Example Analytics'], Yaml::parseFile($this->projectDir.'/config/aggregate.yaml'));
        self::assertFileDoesNotExist($this->projectDir.'/config/secrets/email.yaml');
    }

    public function testACredentialFromTheEnvironmentIsLockedOnThePage(): void
    {
        $_SERVER['EMAIL_SENDGRID_API_KEY'] = 'environment-key';

        $crawler = $this->crawl($this->call('index', $this->request('GET', '/dashboard/email')));
        self::assertCount(1, $crawler->filter('input[name="credential[sendgrid_api_key]"][disabled]'));
        self::assertStringContainsString('EMAIL_SENDGRID_API_KEY', $crawler->filter('.email-provider[data-provider="sendgrid"]')->text());

        $this->call('save', $this->request('POST', '/dashboard/email/save', ['_csrf_token' => 'valid', 'credential' => ['sendgrid_api_key' => 'other']]));
        self::assertStringContainsString('set by EMAIL_SENDGRID_API_KEY', implode(' ', $this->session->getFlashBag()->get('error')));
        self::assertFileDoesNotExist($this->projectDir.'/config/secrets/email.yaml');
    }

    public function testATestMessageGoesToTheAdministratorsOwnAddress(): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['brand_name' => 'Example Analytics', 'email_provider' => 'postmark', 'email_from_address' => 'no-reply@example.com']));
        (new EmailSecrets($this->projectDir))->set((new PostmarkProvider())->credential(), 'token');

        $this->call('test', $this->request('POST', '/dashboard/email/test', ['_csrf_token' => 'valid', 'recipient' => 'someone@else.example'], 'scott@example.com'));

        self::assertSame(['A test email was sent to your address. The service’s message ID is pm-42.'], $this->session->getFlashBag()->get('success'));
        self::assertSame('scott@example.com', $this->sent[0]['To'], 'Never an address from the form');
        $task = $this->connection->fetchAssociative('SELECT task_type, subject, triggered_by, requested_by, external_id FROM processing_tasks');
        self::assertSame(['task_type' => 'email_send', 'subject' => 'test', 'triggered_by' => 'dashboard', 'requested_by' => 'scott', 'external_id' => 'pm-42'], $task);
        $page = $this->crawl($this->call('index', $this->request('GET', '/dashboard/email', [], 'scott@example.com')));
        self::assertStringContainsString('pm-42', $page->filter('.email-sends')->text());

        $this->call('test', $this->request('POST', '/dashboard/email/test', ['_csrf_token' => 'valid']));
        self::assertStringContainsString('Add an email address to your profile', implode(' ', $this->session->getFlashBag()->get('error')));
        self::assertCount(1, $this->sent);
    }

    public function testSignInCodesAreSwitchedOnHereAndWarnUntilEmailCanBeSent(): void
    {
        $crawler = $this->crawl($this->call('index', $this->request('GET', '/dashboard/email')));
        self::assertCount(1, $crawler->filter('.email-sign-in-codes select[name="sign_in_codes_enabled"] option[value="0"][selected]'));

        $this->call('save', $this->request('POST', '/dashboard/email/save', ['_csrf_token' => 'valid', 'sign_in_codes_enabled' => '1']));
        self::assertTrue(Yaml::parseFile($this->projectDir.'/config/aggregate.yaml')['sign_in_codes_enabled']);
        self::assertStringContainsString('Sign-in codes are on, but the sign-in page will not offer them until email can be sent', implode(' ', $this->session->getFlashBag()->get('warning')));

        $this->call('save', $this->request('POST', '/dashboard/email/save', ['_csrf_token' => 'valid', 'sign_in_codes_enabled' => 'maybe']));
        self::assertStringContainsString('sign_in_codes_enabled must be true or false', implode(' ', $this->session->getFlashBag()->get('error')));

        $_SERVER['SIGN_IN_CODES_ENABLED'] = '0';
        $crawler = $this->crawl($this->call('index', $this->request('GET', '/dashboard/email')));
        self::assertCount(1, $crawler->filter('select[name="sign_in_codes_enabled"][disabled]'));
        self::assertStringContainsString('SIGN_IN_CODES_ENABLED', $crawler->filter('.email-sign-in-codes')->text());
        unset($_SERVER['SIGN_IN_CODES_ENABLED']);
    }

    public function testThePageIsUnavailableWithoutTheDashboard(): void
    {
        $_SERVER['DASHBOARD_ENABLED'] = '0';

        $this->expectException(NotFoundHttpException::class);
        $this->call('index', $this->request('GET', '/dashboard/email'));
    }

    private function call(string $action, Request $request, bool $csrfValid = true): Response
    {
        $controller = $this->controller($request, $csrfValid);

        return match ($action) {
            'index' => $controller->index(),
            'save' => $controller->save($request),
            'test' => $controller->test($request),
        };
    }

    private function crawl(Response $response): Crawler
    {
        return new Crawler((string) $response->getContent());
    }

    private function controller(Request $request, bool $csrfValid): EmailSettingsController
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        $settings = new EmailSettings($config, EmailProviders::builtIn());
        $secrets = new EmailSecrets($this->projectDir);
        $tasks = new ProcessingTasks($this->connection, new AuditTrail($this->connection));
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sent[] = json_decode((string) $options['body'], true);

            return new MockResponse('{"MessageID":"pm-42"}', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
        });
        $emailTwig = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'), ['autoescape' => 'name']);
        $mailer = new TransactionalMailer($settings, $secrets, $tasks, $emailTwig, new AppBranding($config, $this->projectDir), $config, new NullLogger(), EmailTestKit::transports($client));
        $controller = new EmailSettingsController($config, $settings, $secrets, $mailer, $tasks, new SignInCodeSettings($config, $mailer), new NullLogger());

        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $csrfValid && in_array($token->getId(), [EmailSettingsController::CSRF_SETTINGS, EmailSettingsController::CSRF_TEST], true));
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/dashboard/email');
        $stack = new RequestStack();
        $stack->push($request);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($this->user($request->attributes->get('_test_email')), 'main', ['ROLE_ADMIN']));

        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.html.twig' => '<title>{% block title %}{% endblock %}</title><body>{% block body %}{% endblock %}</body>']),
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('path', static fn (string $route): string => match ($route) {
            'app_email_save' => '/dashboard/email/save',
            'app_email_test' => '/dashboard/email/test',
            'app_profile' => '/dashboard/profile',
            'app_dashboard' => '/dashboard',
        }));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'rendered-token'));
        \App\Tests\Support\TwigComponents::register($twig);

        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.token_storage', $tokens);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    private function user(?string $email): User
    {
        $user = (new User())->setUsername('scott')->setRoles(['ROLE_ADMIN']);
        $user->setPassword('hash');
        $user->setEmail($email);

        return $user;
    }

    /** @param array<string, mixed> $form */
    private function request(string $method, string $path, array $form = [], ?string $email = null): Request
    {
        $request = Request::create($path, $method, $form);
        $request->attributes->set('_test_email', $email);
        $this->session = new Session(new MockArraySessionStorage());
        $request->setSession($this->session);

        return $request;
    }
}
