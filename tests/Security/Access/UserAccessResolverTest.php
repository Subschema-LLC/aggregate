<?php

declare(strict_types=1);

namespace App\Tests\Security\Access;

use App\Entity\User;
use App\Security\Access\DashboardAccessVoter;
use App\Security\Access\DashboardPages;
use App\Security\Access\PermissionGroupCatalog;
use App\Security\Access\UserAccessResolver;
use App\Service\WebsiteScopeCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Yaml\Yaml;

final class UserAccessResolverTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-user-access-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/website-scopes.yaml', Yaml::dump([
            'groups' => ['brand-a' => ['name' => 'Brand A', 'members' => ['token-a1', 'token-a2']], 'brand-b' => ['name' => 'Brand B', 'members' => ['token-b1']]],
        ], 4));
        file_put_contents($this->projectDir.'/config/permission-groups.yaml', Yaml::dump(['permission_groups' => [
            'editors' => ['name' => 'Editors', 'websites' => ['groups' => ['brand-a']], 'pages' => ['tag_manager' => 'edit', 'websites' => 'view']],
            'analysts' => ['name' => 'Analysts', 'websites' => ['all' => true], 'pages' => ['websites' => 'edit', 'reporting_views' => 'view']],
        ]], 5));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testAdministratorsCanDoEverything(): void
    {
        $access = $this->resolver()->forUser($this->user(admin: true));
        self::assertTrue($access->isAdmin());
        self::assertSame(DashboardPages::EDIT, $access->level('updates'));
        self::assertTrue($access->allowsEveryWebsite());
        self::assertTrue($access->allowsToken('any-token'));
        self::assertNull($access->level('users'), 'users is not a page anyone is granted');
        self::assertTrue(DashboardAccessVoter::satisfies($access, DashboardPages::requirement('app_users')));
    }

    public function testAStandardUserWithoutGrantsCanUseOnlyTheirProfile(): void
    {
        $access = $this->resolver()->forUser($this->user());
        self::assertFalse($access->hasAnyPage());
        self::assertFalse($access->allowsToken('token-a1'));
        self::assertFalse($access->allowsEveryWebsite());
        self::assertTrue(DashboardAccessVoter::satisfies($access, DashboardPages::requirement('app_profile_password', 'POST')));
        self::assertFalse(DashboardAccessVoter::satisfies($access, DashboardPages::requirement('app_dashboard')));
        self::assertFalse(DashboardAccessVoter::satisfies($access, DashboardPages::requirement('app_users')));
        self::assertFalse(DashboardAccessVoter::satisfies($access, DashboardPages::requirement('app_unlisted_new_page')), 'unlisted routes are administrator-only');
    }

    public function testGrantsFromGroupsAndTheUserAddUpToTheHighestLevel(): void
    {
        $user = $this->user(['permission_groups' => ['editors'], 'websites' => ['sites' => ['token-b1']], 'pages' => ['tag_manager' => 'view', 'setup' => 'view']]);
        $access = $this->resolver()->forUser($user);

        self::assertSame(DashboardPages::EDIT, $access->level('tag_manager'), 'the group gives edit; the user view');
        self::assertSame(DashboardPages::VIEW, $access->level('websites'));
        self::assertSame(DashboardPages::VIEW, $access->level('setup'));
        self::assertNull($access->level('consent_manager'));
        self::assertTrue($access->allowsToken('token-a2'), 'through the Brand A website group');
        self::assertTrue($access->allowsToken('token-b1'), 'granted directly');
        self::assertFalse($access->allowsToken('token-c'));
        self::assertTrue($access->allowsGroup('brand-a'));
        self::assertFalse($access->allowsGroup('brand-b'));
        self::assertFalse($access->allowsEveryWebsite());

        $summary = $access->summary();
        self::assertSame(['setup', 'websites', 'tag_manager'], array_column($summary['pages'], 'page'), 'in menu order');
        $tags = $summary['pages'][2];
        self::assertSame([['label' => 'Assigned directly', 'level' => 'view'], ['label' => 'Permission group: Editors', 'level' => 'edit']], $tags['sources']);
        self::assertSame(['brand-a' => ['Permission group: Editors']], $summary['groups']);
        self::assertSame(['token-b1' => ['Assigned directly']], $summary['sites']);
        self::assertSame([], $summary['every_website']);
    }

    public function testEveryWebsiteCoversNewWebsitesAndSharedSettings(): void
    {
        $access = $this->resolver()->forUser($this->user(['permission_groups' => ['analysts']]));
        self::assertTrue($access->allowsEveryWebsite());
        self::assertTrue($access->allowsToken('added-later'));
        self::assertTrue(DashboardAccessVoter::satisfies($access, DashboardPages::requirement('app_website_create', 'POST')));
        self::assertFalse(DashboardAccessVoter::satisfies($access, DashboardPages::requirement('app_data_model_regenerate', 'POST')), 'reporting views is view only');
        self::assertSame(['Permission group: Analysts'], $access->summary()['every_website']);
    }

    public function testMissingGroupsAndInvalidFilesGrantNothing(): void
    {
        $user = $this->user(['permission_groups' => ['editors', 'removed']]);
        $access = $this->resolver()->forUser($user);
        self::assertSame(['removed'], $access->summary()['missing_permission_groups']);
        self::assertSame(DashboardPages::EDIT, $access->level('tag_manager'));

        file_put_contents($this->projectDir.'/config/permission-groups.yaml', "permission_groups:\n  editors: { name: Editors, pages: { users: edit } }\n");
        self::assertNull($this->resolver()->forUser($user)->level('tag_manager'), 'an invalid permission group file grants nothing');

        file_put_contents($this->projectDir.'/config/website-scopes.yaml', "groups: [\n");
        $user = $this->user(['websites' => ['groups' => ['brand-a']], 'pages' => ['websites' => 'view']]);
        self::assertFalse($this->resolver()->forUser($user)->allowsToken('token-a1'), 'an invalid website group file grants no group members');
    }

    public function testADamagedStoredValueGrantsNothing(): void
    {
        $user = $this->user();
        (new \ReflectionProperty($user, 'access'))->setValue($user, ['pages' => ['tag_manager' => 'owner']]);
        self::assertFalse($this->resolver()->forUser($user)->hasAnyPage());
    }

    public function testTheVoterChecksRoutesByMethodAndPagesByLevel(): void
    {
        $resolver = $this->resolver();
        $voter = new DashboardAccessVoter($resolver);
        $user = $this->user(['websites' => ['sites' => ['token-a1']], 'pages' => ['tag_manager' => 'view', 'setup' => 'edit']]);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $vote = static fn (string $attribute, mixed $subject = null): int => $voter->vote($token, $subject, [$attribute]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $vote(DashboardAccessVoter::ROUTE, 'app_tag_manager'));
        self::assertSame(VoterInterface::ACCESS_DENIED, $vote(DashboardAccessVoter::ROUTE, 'app_tag_manager_save'));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $vote(DashboardAccessVoter::ROUTE, $this->request('app_standalone_consent', 'GET')));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $vote(DashboardAccessVoter::ROUTE, $this->request('app_standalone_consent', 'POST')), 'setup edit');
        self::assertSame(VoterInterface::ACCESS_DENIED, $vote(DashboardAccessVoter::ROUTE, $this->request('app_setup_build_scripts', 'POST')), 'needs every website');
        self::assertSame(VoterInterface::ACCESS_DENIED, $vote(DashboardAccessVoter::ROUTE, $this->request('app_tag_manager_save', 'POST')));
        self::assertSame(VoterInterface::ACCESS_DENIED, $vote(DashboardAccessVoter::ROUTE, new Request()), 'a request without a route');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $vote(DashboardAccessVoter::PAGE_VIEW, 'tag_manager'));
        self::assertSame(VoterInterface::ACCESS_DENIED, $vote(DashboardAccessVoter::PAGE_EDIT, 'tag_manager'));
        self::assertSame(VoterInterface::ACCESS_DENIED, $vote(DashboardAccessVoter::EVERY_WEBSITE));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $vote('ROLE_ADMIN'));

        $admin = $this->user(admin: true);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new UsernamePasswordToken($admin, 'main', $admin->getRoles()), 'app_users', [DashboardAccessVoter::ROUTE]));
    }

    private function request(string $route, string $method): Request
    {
        $request = Request::create('/dashboard/example', $method);
        $request->attributes->set('_route', $route);

        return $request;
    }

    /** @param array<string, mixed> $access */
    private function user(array $access = [], bool $admin = false): User
    {
        return (new User())->setUsername('person-'.bin2hex(random_bytes(3)))->setRoles([])->setAdmin($admin)->setPassword('unused')->setAccess($access);
    }

    private function resolver(): UserAccessResolver
    {
        return new UserAccessResolver(new PermissionGroupCatalog($this->projectDir, 'test'), new WebsiteScopeCatalog($this->projectDir, 'test'));
    }
}
