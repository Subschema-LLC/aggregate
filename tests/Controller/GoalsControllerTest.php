<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\GoalsController;
use App\Service\AggregateConfigLoader;
use App\Service\GoalDefinitions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class GoalsControllerTest extends TestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['DASHBOARD_ENABLED', 'SERVER_ROLE'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->directory = sys_get_temp_dir().'/aggregate-goals-ui-'.bin2hex(random_bytes(6));
        $fs = new Filesystem();
        $fs->dumpFile($this->directory.'/config/aggregate.yaml', "app_host: 'https://analytics.example.com'\n");
        $fs->dumpFile($this->directory.'/'.GoalDefinitions::SHIPPED_FILE, "parameters:\n    app.goal_events:\n        purchase: { label: Purchase, enabled: true, anonymous: true }\n        lead: { label: Lead, enabled: true, anonymous: false }\n");
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testThePageListsTheGoalsAndOffersRowsForNewOnes(): void
    {
        $response = $this->controller(Request::create('/dashboard/goals'))->index();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $crawler = new Crawler((string) $response->getContent());
        self::assertSame(['purchase', 'lead'], $crawler->filter('tbody code')->each(static fn (Crawler $code): string => $code->text()));
        self::assertCount(3, $crawler->filter('input[placeholder="New goal code"]'));
        self::assertCount(1, $crawler->filter('input[name="goals[1][anonymous]"]:not([checked])'), 'lead is not allowed without consent.');
        self::assertStringContainsString('shipped defaults', $crawler->filter('.notification.is-info')->text());
    }

    public function testSavingAddsDisablesAndRemovesGoalsInTheLocalFile(): void
    {
        $request = Request::create('/dashboard/goals/save', 'POST', [
            '_csrf_token' => 'valid-token',
            'goals' => [
                ['existing' => '1', 'code' => 'purchase', 'label' => 'Order placed', 'enabled' => '1', 'anonymous' => '1'],
                ['existing' => '1', 'code' => 'lead', 'label' => 'Lead', 'remove' => '1'],
                ['code' => 'newsletter', 'label' => 'Newsletter', 'anonymous' => '1'],
                ['code' => '', 'label' => '', 'enabled' => '1', 'anonymous' => '1'],
            ],
        ]);
        $response = $this->controller($request)->save($request);

        self::assertInstanceOf(RedirectResponse::class, $response);
        $saved = Yaml::parseFile($this->directory.'/'.GoalDefinitions::LOCAL_FILE)['parameters'][GoalDefinitions::PARAMETER];
        self::assertSame([
            'purchase' => ['label' => 'Order placed', 'enabled' => true, 'anonymous' => true],
            'newsletter' => ['label' => 'Newsletter', 'enabled' => false, 'anonymous' => true],
        ], $saved);
    }

    public function testARefusedSaveKeepsWhatWasTypedAndWritesNothing(): void
    {
        $request = Request::create('/dashboard/goals/save', 'POST', [
            '_csrf_token' => 'valid-token',
            'goals' => [['code' => 'order 42', 'label' => 'Order', 'enabled' => '1']],
        ]);
        $response = $this->controller($request)->save($request);

        self::assertSame(422, $response->getStatusCode());
        $crawler = new Crawler((string) $response->getContent());
        self::assertStringContainsString('safe event-name format', $crawler->filter('.notification.is-danger')->text());
        self::assertSame('order 42', $crawler->filter('input[name="goals[0][code]"]')->attr('value'));
        self::assertFileDoesNotExist($this->directory.'/'.GoalDefinitions::LOCAL_FILE);
    }

    public function testAnInvalidTokenSavesNothing(): void
    {
        $request = Request::create('/dashboard/goals/save', 'POST', ['_csrf_token' => 'wrong', 'goals' => [['code' => 'x', 'label' => 'X']]]);
        $this->controller($request)->save($request);

        self::assertFileDoesNotExist($this->directory.'/'.GoalDefinitions::LOCAL_FILE);
    }

    public function testADuplicatedCodeIsRefused(): void
    {
        $this->expectExceptionMessage('listed twice');
        GoalsController::fromRows([['code' => 'purchase', 'label' => 'A'], ['code' => 'purchase', 'label' => 'B']]);
    }

    private function controller(Request $request): GoalsController
    {
        $config = new AggregateConfigLoader($this->directory, 'test');
        $controller = new GoalsController($config, new GoalDefinitions($this->directory), new NullLogger());

        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getId() === GoalsController::CSRF_TOKEN_ID && $token->getValue() === 'valid-token');
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(self::url(...));
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);
        $twig = new Environment(new ChainLoader([new ArrayLoader(['base.html.twig' => '{% block body %}{% endblock %}']), new FilesystemLoader(dirname(__DIR__, 2).'/templates')]), ['strict_variables' => true]);
        $twig->addGlobal('app_branding', ['name' => 'Example Analytics']);
        $twig->addFunction(new TwigFunction('path', self::url(...)));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'valid-token'));
        \App\Tests\Support\TwigComponents::register($twig);

        $container = new Container();
        $container->set('security.authorization_checker', $authorization);
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('request_stack', $stack);
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    private static function url(string $name, array $parameters = []): string
    {
        return $name === 'app_goals_save' ? '/dashboard/goals/save' : '/dashboard/goals';
    }
}
