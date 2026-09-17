<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\SecurityController;
use App\Entity\User;
use App\Kernel;
use App\Service\AggregateConfigLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class AuthenticationProtectionTest extends TestCase
{
    private string $temporaryDirectory;
    private array $environment;
    private ?AuthenticationProtectionTestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        $this->temporaryDirectory = sys_get_temp_dir().'/aggregate-authentication-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->temporaryDirectory.'/config', 0700);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    #[DataProvider('sameOriginHeaders')]
    public function testTheRenderedLoginFormAuthenticatesSameOriginRequests(array $headers): void
    {
        $browser = $this->browser();
        $form = $browser->request('GET', '/login')->selectButton('Sign in')->form([
            '_username' => 'beta-admin',
            '_password' => AuthenticationTestUserProvider::PASSWORD,
        ]);
        self::assertNotSame('', $form['_csrf_token']->getValue());
        $browser->getHistory()->clear();
        $browser->submit($form, [], $headers);

        $this->assertRedirectPath($browser, '/dashboard');
        $browser->request('GET', '/login');
        $this->assertRedirectPath($browser, '/dashboard');
        $this->assertNoDatabaseConnection();
    }

    public static function sameOriginHeaders(): iterable
    {
        yield 'fetch metadata' => [['HTTP_SEC_FETCH_SITE' => 'same-origin']];
        yield 'origin fallback' => [['HTTP_ORIGIN' => 'http://localhost']];
        yield 'referer fallback without JavaScript' => [['HTTP_REFERER' => 'http://localhost/login']];
    }

    #[DataProvider('invalidCsrfRequests')]
    public function testLoginRejectsMissingForgedAndCrossOriginCsrfBeforeAuthenticating(?string $token, array $headers): void
    {
        $browser = $this->browser();
        $renderedToken = $this->loginToken($browser);
        $parameters = ['_username' => 'beta-admin', '_password' => AuthenticationTestUserProvider::PASSWORD];
        if ($token !== null) {
            $parameters['_csrf_token'] = $token === 'rendered' ? $renderedToken : $token;
        }
        $browser->getHistory()->clear();
        $browser->request('POST', '/login', $parameters, [], $headers);

        $this->assertRedirectPath($browser, '/login');
        self::assertInstanceOf(InvalidCsrfTokenException::class, $browser->getRequest()->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR));
        $browser->request('GET', '/dashboard/feature-flags');
        $this->assertRedirectPath($browser, '/login');
        $this->assertNoDatabaseConnection();
    }

    public static function invalidCsrfRequests(): iterable
    {
        yield 'missing token even on same origin' => [null, ['HTTP_ORIGIN' => 'http://localhost']];
        yield 'forged token even on same origin' => ['forged', ['HTTP_ORIGIN' => 'http://localhost']];
        yield 'rendered token without origin evidence' => ['rendered', []];
        yield 'forged long token without origin evidence' => [str_repeat('x', 32), []];
        yield 'rendered token from another origin' => ['rendered', ['HTTP_ORIGIN' => 'https://attacker.example', 'HTTP_REFERER' => 'https://attacker.example/form']];
        yield 'hostname prefix is not same origin' => ['rendered', ['HTTP_ORIGIN' => 'http://localhost.attacker.example']];
        yield 'cross-site fetch metadata overrides matching origin' => ['rendered', ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'http://localhost']];
    }

    public function testNavigationGeneratesAWorkingCsrfProtectedLogoutLink(): void
    {
        $browser = $this->browser();
        $this->login($browser);
        $crawler = $browser->request('GET', '/dashboard/feature-flags');
        self::assertSame(200, $browser->getResponse()->getStatusCode());
        $link = $crawler->selectLink('Logout')->link();
        parse_str((string) parse_url($link->getUri(), PHP_URL_QUERY), $query);
        self::assertSame('/logout', parse_url($link->getUri(), PHP_URL_PATH));
        self::assertNotEmpty($query['_csrf_token']);

        // BrowserKit supplies the same-origin Referer, as a normal navigation does.
        $browser->click($link);

        $this->assertRedirectPath($browser, '/');
        $browser->request('GET', '/dashboard/feature-flags');
        $this->assertRedirectPath($browser, '/login');
        $this->assertNoDatabaseConnection();
    }

    #[DataProvider('invalidCsrfRequests')]
    public function testInvalidLogoutRequestsKeepTheAuthenticatedSession(?string $token, array $headers): void
    {
        $browser = $this->browser();
        $this->login($browser);
        $link = $browser->request('GET', '/dashboard/feature-flags')->selectLink('Logout')->link();
        parse_str((string) parse_url($link->getUri(), PHP_URL_QUERY), $query);
        $parameters = $token === null ? [] : ['_csrf_token' => $token === 'rendered' ? $query['_csrf_token'] : $token];
        $browser->getHistory()->clear();
        $browser->request('GET', '/logout', $parameters, [], $headers);

        self::assertSame(403, $browser->getResponse()->getStatusCode());
        $browser->request('GET', '/login');
        $this->assertRedirectPath($browser, '/dashboard');
        $this->assertNoDatabaseConnection();
    }

    public function testRepeatedFailedLoginsAreThrottledAcrossSessionsAndUsernameCase(): void
    {
        $browser = $this->browser();
        $csrf = $this->loginToken($browser);
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $browser->request('POST', '/login', [
                '_username' => $attempt % 2 ? 'BETA-ADMIN' : 'beta-admin',
                '_password' => 'incorrect-password',
                '_csrf_token' => $csrf,
            ], [], ['HTTP_ORIGIN' => 'http://localhost']);
            $this->assertRedirectPath($browser, '/login');
            self::assertInstanceOf(BadCredentialsException::class, $browser->getRequest()->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR));
        }

        $browser->restart();
        $this->submitValidCredentials($browser);
        $this->assertRedirectPath($browser, '/login');
        self::assertInstanceOf(TooManyLoginAttemptsAuthenticationException::class, $browser->getRequest()->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR));

        // A different client IP has its own budget; the account is not globally locked.
        $this->submitValidCredentials($browser, ['REMOTE_ADDR' => '192.0.2.20']);
        $this->assertRedirectPath($browser, '/dashboard');
        $this->assertNoDatabaseConnection();
    }

    public function testOneIpCannotBypassThrottlingByCyclingThroughUsernames(): void
    {
        $browser = $this->browser();
        $csrf = $this->loginToken($browser);
        for ($attempt = 0; $attempt < 25; ++$attempt) {
            $browser->request('POST', '/login', [
                '_username' => 'unknown-'.$attempt,
                '_password' => 'incorrect-password',
                '_csrf_token' => $csrf,
            ], [], ['HTTP_ORIGIN' => 'http://localhost']);
            self::assertInstanceOf(BadCredentialsException::class, $browser->getRequest()->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR));
        }

        $this->submitValidCredentials($browser);
        $this->assertRedirectPath($browser, '/login');
        self::assertInstanceOf(TooManyLoginAttemptsAuthenticationException::class, $browser->getRequest()->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR));
        $this->assertNoDatabaseConnection();
    }

    public function testHeadlessBootKeepsAuthenticationRoutesUnavailable(): void
    {
        $browser = $this->browser(false);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertFalse($container->has(SecurityController::class));
        $routes = $container->get('router')->getRouteCollection();
        self::assertNull($routes->get('app_login'));
        self::assertNull($routes->get('app_logout'));

        foreach (['GET', 'POST'] as $method) {
            foreach (['/login', '/logout'] as $path) {
                $browser->request($method, $path, [
                    '_username' => 'beta-admin',
                    '_password' => AuthenticationTestUserProvider::PASSWORD,
                    '_csrf_token' => 'csrf-token',
                ], [], ['HTTP_ORIGIN' => 'http://localhost']);
                self::assertSame(404, $browser->getResponse()->getStatusCode());
            }
        }
        $this->assertNoDatabaseConnection();
    }

    private function browser(bool $dashboardEnabled = true): KernelBrowser
    {
        $_ENV['DASHBOARD_ENABLED'] = $_SERVER['DASHBOARD_ENABLED'] = $dashboardEnabled ? '1' : '0';
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '';
        $this->kernel = new AuthenticationProtectionTestKernel($this->temporaryDirectory);
        $this->kernel->boot();
        $browser = new KernelBrowser($this->kernel);
        $browser->disableReboot();

        return $browser;
    }

    private function loginToken(KernelBrowser $browser): string
    {
        $crawler = $browser->request('GET', '/login');
        self::assertSame(200, $browser->getResponse()->getStatusCode());

        return (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
    }

    private function submitValidCredentials(KernelBrowser $browser, array $server = []): void
    {
        $csrf = $this->loginToken($browser);
        $browser->request('POST', '/login', [
            '_username' => 'beta-admin',
            '_password' => AuthenticationTestUserProvider::PASSWORD,
            '_csrf_token' => $csrf,
        ], [], ['HTTP_ORIGIN' => 'http://localhost', ...$server]);
    }

    private function login(KernelBrowser $browser): void
    {
        $this->submitValidCredentials($browser);
        $this->assertRedirectPath($browser, '/dashboard');
    }

    private function assertRedirectPath(KernelBrowser $browser, string $path): void
    {
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame($path, parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    private function assertNoDatabaseConnection(): void
    {
        self::assertFalse($this->kernel->getContainer()->get('test.service_container')->get('doctrine.dbal.default_connection')->isConnected());
    }
}

/** Compile the production firewall unchanged, replacing only its persistence boundary. */
final class AuthenticationProtectionTestKernel extends Kernel implements CompilerPassInterface
{
    public function __construct(private readonly string $temporaryDirectory)
    {
        parent::__construct('test', true);
    }

    public function getCacheDir(): string
    {
        return $this->temporaryDirectory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->temporaryDirectory.'/log';
    }

    protected function getContainerClass(): string
    {
        return parent::getContainerClass().'_'.md5($this->temporaryDirectory);
    }

    public function process(ContainerBuilder $container): void
    {
        $container->setDefinition('security.user.provider.concrete.app_user_provider', new Definition(AuthenticationTestUserProvider::class));
        $container->getDefinition(AggregateConfigLoader::class)->setArgument('$projectDir', $this->temporaryDirectory);
    }
}

/** @implements UserProviderInterface<User> */
final class AuthenticationTestUserProvider implements UserProviderInterface
{
    public const PASSWORD = 'beta-test-password-only';

    private User $user;

    public function __construct()
    {
        $this->user = (new User())
            ->setUsername('beta-admin')
            ->setPassword(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]))
            ->setRoles(['ROLE_ADMIN']);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if (strtolower($identifier) !== $this->user->getUserIdentifier()) {
            throw new UserNotFoundException();
        }

        return clone $this->user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === User::class;
    }
}
