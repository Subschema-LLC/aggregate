<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\AdminAuditSubscriber;
use App\EventSubscriber\DashboardAccessSubscriber;
use App\Security\Access\DashboardAccessVoter;
use App\Security\Access\PermissionGroupCatalog;
use App\Security\Access\UserAccessResolver;
use App\Service\AggregateConfigLoader;
use App\Service\WebsiteScopeCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Yaml\Yaml;

final class DashboardAccessSubscriberTest extends TestCase
{
    private string $projectDir;
    private TokenStorage $tokens;
    private UserAccessResolver $resolver;
    private bool $controllerRan = false;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-dashboard-access-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['installed' => true]));
        $this->tokens = new TokenStorage();
        $this->resolver = new UserAccessResolver(new PermissionGroupCatalog($this->projectDir, 'test'), new WebsiteScopeCatalog($this->projectDir, 'test'));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testListensBeforeControllersButAfterTheAuditTrailStartsRecording(): void
    {
        self::assertSame([\Symfony\Component\HttpKernel\KernelEvents::CONTROLLER => ['onController', -16]], DashboardAccessSubscriber::getSubscribedEvents());
    }

    public function testPagesOutsideTheDashboardAndAllowedPagesAreLeftAlone(): void
    {
        $this->signIn(['pages' => ['tag_manager' => 'view'], 'websites' => ['all' => true]]);
        foreach ([['/how-it-works', 'app_how_it_works', 'GET'], ['/dashboard/tag-manager', 'app_tag_manager', 'GET'], ['/dashboard/profile', 'app_profile', 'GET']] as [$path, $route, $method]) {
            $event = $this->event($path, $route, $method);
            $this->subscriber()->onController($event);
            self::assertSame('original', ($event->getController())()->getContent(), $route);
        }
    }

    public function testAPageWithoutAccessIsForbidden(): void
    {
        $this->signIn(['pages' => ['tag_manager' => 'view']]);
        $refused = [];
        foreach ([['/dashboard/settings', 'app_application_settings', 'GET'], ['/dashboard/users', 'app_users', 'GET'], ['/dashboard/new-page', 'app_new_page', 'GET'], ['/dashboard/settings/save', 'app_settings_save', 'POST']] as [$path, $route, $method]) {
            try {
                $this->subscriber()->onController($this->event($path, $route, $method));
            } catch (AccessDeniedException) {
                $refused[] = $route;
            }
        }
        self::assertSame(['app_application_settings', 'app_users', 'app_new_page', 'app_settings_save'], $refused, 'unlisted dashboard routes are administrator-only');
        self::assertFalse($this->controllerRan);
    }

    public function testAViewOnlySaveGoesBackToThePageWithAMessageAndIsRecordedAsDenied(): void
    {
        $this->signIn(['pages' => ['tag_manager' => 'view'], 'websites' => ['all' => true]]);
        $event = $this->event('/dashboard/tag-manager/save', 'app_tag_manager_save', 'POST', ['HTTP_REFERER' => 'http://localhost/dashboard/tag-manager?site=abc']);
        $this->subscriber()->onController($event);

        $response = ($event->getController())();
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('http://localhost/dashboard/tag-manager?site=abc', $response->headers->get('Location'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame(['You have view-only access to Tag manager lite. Ask an administrator for edit access.'], $event->getRequest()->getSession()->getFlashBag()->get('error'));
        self::assertTrue($event->getRequest()->attributes->get(AdminAuditSubscriber::REFUSED));
        self::assertFalse($this->controllerRan);

        // Another site's address is not followed; the page's own route is.
        $event = $this->event('/dashboard/tag-manager/save', 'app_tag_manager_save', 'POST', ['HTTP_REFERER' => 'https://elsewhere.example/dashboard']);
        $this->subscriber()->onController($event);
        self::assertSame('/generated/app_tag_manager', ($event->getController())()->headers->get('Location'));
    }

    public function testAChangeThatNeedsEveryWebsiteSaysSo(): void
    {
        $this->signIn(['pages' => ['websites' => 'edit'], 'websites' => ['sites' => ['token-a']]]);
        $event = $this->event('/dashboard/website/create', 'app_website_create', 'POST');
        $this->subscriber()->onController($event);
        ($event->getController())();
        self::assertSame(['That change on Websites needs access to every website. Ask an administrator if you need it.'], $event->getRequest()->getSession()->getFlashBag()->get('error'));
    }

    public function testBackgroundRequestsGetAForbiddenResponseRatherThanARedirect(): void
    {
        $this->signIn(['pages' => ['tag_manager' => 'view']]);
        $this->expectException(AccessDeniedException::class);
        $this->subscriber()->onController($this->event('/dashboard/tag-manager/save', 'app_tag_manager_save', 'POST', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
    }

    public function testTheDashboardHomeOpensTheFirstPageTheUserCanUseOrTheirProfile(): void
    {
        $this->signIn(['pages' => ['consent_manager' => 'view', 'branding' => 'edit']]);
        $event = $this->event('/dashboard', 'app_dashboard', 'GET');
        $this->subscriber()->onController($event);
        self::assertSame('/generated/app_consent_manager', ($event->getController())()->headers->get('Location'));

        $this->signIn([]);
        $event = $this->event('/dashboard', 'app_dashboard', 'GET');
        $this->subscriber()->onController($event);
        self::assertSame('/generated/app_profile', ($event->getController())()->headers->get('Location'));
    }

    public function testADisabledDashboardIsLeftToAnswerNotFound(): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['installed' => true, 'dashboard_enabled' => false]));
        $this->signIn([]);
        $event = $this->event('/dashboard/settings', 'app_application_settings', 'GET');
        $this->subscriber()->onController($event);
        self::assertSame('original', ($event->getController())()->getContent());
    }

    /** @param array<string, mixed> $access */
    private function signIn(array $access): void
    {
        $user = (new User())->setUsername('standard')->setRoles([])->setPassword('unused')->setAccess($access);
        $this->tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    /** @param array<string, string> $server */
    private function event(string $path, string $route, string $method, array $server = []): ControllerEvent
    {
        $request = Request::create($path, $method, server: $server);
        $request->attributes->set('_route', $route);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->controllerRan = false;

        return new ControllerEvent($this->createStub(HttpKernelInterface::class), function (): Response {
            $this->controllerRan = true;

            return new Response('original');
        }, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function subscriber(): DashboardAccessSubscriber
    {
        $voter = new DashboardAccessVoter($this->resolver);
        $authorization = $this->createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(fn (string $attribute, mixed $subject = null): bool => $voter->vote($this->tokens->getToken(), $subject, [$attribute]) === 1);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route): string => '/generated/'.$route);

        return new DashboardAccessSubscriber($authorization, $this->tokens, $this->resolver, $urls, new AggregateConfigLoader($this->projectDir, 'test'));
    }
}
