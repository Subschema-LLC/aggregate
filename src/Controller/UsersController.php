<?php

namespace App\Controller;

use App\Entity\User;
use App\EventSubscriber\AdminAuditSubscriber;
use App\Repository\UserRepository;
use App\Security\Access\AccessGrant;
use App\Security\Access\DashboardPages;
use App\Security\Access\PermissionGroupCatalog;
use App\Security\Access\UserAdministration;
use App\Service\AggregateConfigLoader;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Users and permission groups, which only administrators manage. Accounts are
 * administrators, who can do everything, or standard users, who can use the
 * websites and pages their permission groups and their own grants allow.
 */
class UsersController extends AbstractController
{
    use DashboardAccess;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly UserRepository $userRepository,
        private readonly UserAdministration $administration,
        private readonly PermissionGroupCatalog $permissionGroups,
        private readonly WebsiteConfigManager $websites,
        private readonly WebsiteScopeCatalog $websiteGroups,
    ) {}

    #[Route('/dashboard/users', name: 'app_users', methods: ['GET'])]
    public function users(): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        return $this->renderDashboardPage('users/index.html.twig', [
            ...$this->formContext(),
            'users' => $this->userRepository->findBy([], ['createdAt' => 'ASC']),
            'current_tab' => 'app_users',
        ]);
    }

    #[Route('/dashboard/users/create', name: 'app_user_create', methods: ['POST'])]
    public function createUser(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();
        if (!$this->isCsrfTokenValid('create_user', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_users');
        }

        $username = trim((string) $request->request->get('username', ''));
        $isAdmin = $request->request->get('account_type') === 'admin' || $request->request->getBoolean('is_admin');
        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $username);
        $request->attributes->set(AdminAuditSubscriber::DETAILS, $isAdmin ? 'Administrator account.' : 'Standard account.');
        try {
            $groups = $request->request->all('permission_groups');
            $user = $this->administration->create(
                $username,
                (string) $request->request->get('password', ''),
                $isAdmin,
                $isAdmin ? [] : ['permission_groups' => array_values($groups)],
                (string) $request->request->get('display_name', ''),
                (string) $request->request->get('email', ''),
            );
            $this->addFlash('success', $isAdmin
                ? sprintf('Administrator "%s" created.', $user->getUsername())
                : sprintf('User "%s" created. Open their account below to choose websites and pages.', $user->getUsername()));

            return $this->redirectToRoute('app_users', ['_fragment' => 'user-'.$user->getId()]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'The user could not be created. Check the database connection and try again.');
        }

        return $this->redirectToRoute('app_users');
    }

    #[Route('/dashboard/users/{id}/update', name: 'app_user_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUser(Request $request, int $id): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();
        if (!$this->isCsrfTokenValid('update_user_'.$id, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_users');
        }
        $user = $this->findUser($id);
        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $user->getUsername());
        $actor = $this->getUser();
        try {
            $form = $request->request->all();
            $isAdmin = ($form['account_type'] ?? null) === 'admin';
            $access = $isAdmin ? $user->getAccess() : ['permission_groups' => array_values($request->request->all('permission_groups'))] + AccessGrant::fromForm($form);
            $wasAdmin = $user->isAdmin();
            $this->administration->update(
                $user,
                (string) ($form['username'] ?? ''),
                is_string($form['display_name'] ?? null) ? $form['display_name'] : null,
                is_string($form['email'] ?? null) ? $form['email'] : null,
                $isAdmin,
                $access,
                $actor instanceof User ? $actor : null,
            );
            $request->attributes->set(AdminAuditSubscriber::DETAILS, $this->auditDetails($wasAdmin, $isAdmin, $access));
            $this->addFlash('success', sprintf('Saved "%s".', $user->getUsername()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'The user could not be saved. Check the database connection and try again.');
        }

        return $this->redirectToRoute('app_users', ['_fragment' => 'user-'.$id]);
    }

    #[Route('/dashboard/users/{id}/password', name: 'app_user_password_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUserPassword(Request $request, int $id): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();
        if (!$this->isCsrfTokenValid('update_user_password_'.$id, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_users');
        }
        $user = $this->findUser($id);
        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $user->getUsername());
        try {
            $this->administration->changePassword($user, (string) $request->request->get('new_password', ''));
            $this->addFlash('success', sprintf('Password updated for "%s". They are signed out of other sessions.', $user->getUsername()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'The password could not be updated. Check the database connection and try again.');
        }

        return $this->redirectToRoute('app_users', ['_fragment' => 'user-'.$id]);
    }

    #[Route('/dashboard/users/{id}/delete', name: 'app_user_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteUser(Request $request, int $id): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();
        if (!$this->isCsrfTokenValid('delete_user_'.$id, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_users');
        }
        $user = $this->findUser($id);
        $username = $user->getUsername();
        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $username);
        $actor = $this->getUser();
        try {
            $this->administration->delete($user, $actor instanceof User ? $actor : null);
            $this->addFlash('success', sprintf('Deleted "%s".', $username));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'The user could not be deleted. Check the database connection and try again.');
        }

        return $this->redirectToRoute('app_users');
    }

    #[Route('/dashboard/users/permission-groups', name: 'app_permission_groups', methods: ['GET'])]
    public function permissionGroups(): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        return $this->renderDashboardPage('users/permission_groups.html.twig', [
            ...$this->formContext(),
            'members' => $this->safeMemberCounts(),
            'current_tab' => 'app_permission_groups',
        ]);
    }

    #[Route('/dashboard/users/permission-groups/create', name: 'app_permission_groups_create', methods: ['POST'])]
    public function createPermissionGroup(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();
        if (!$this->isCsrfTokenValid('create_permission_group', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_permission_groups');
        }
        $name = trim((string) $request->request->get('name', ''));
        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $name);
        try {
            $id = $this->permissionGroups->create($name, (string) $request->request->get('description', ''), AccessGrant::fromForm($request->request->all()));
            $this->addFlash('success', sprintf('Permission group "%s" created. Assign it to users on the Users tab.', $name));

            return $this->redirectToRoute('app_permission_groups', ['_fragment' => 'group-'.$id]);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_permission_groups');
    }

    #[Route('/dashboard/users/permission-groups/{id}/update', name: 'app_permission_groups_update', methods: ['POST'], requirements: ['id' => '[a-z0-9][a-z0-9-]{0,62}'])]
    public function updatePermissionGroup(Request $request, string $id): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();
        if (!$this->isCsrfTokenValid('update_permission_group_'.$id, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_permission_groups');
        }
        $name = trim((string) $request->request->get('name', ''));
        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $this->permissionGroups->group($id)['name'] ?? $id);
        try {
            $this->permissionGroups->update($id, $name, (string) $request->request->get('description', ''), AccessGrant::fromForm($request->request->all()));
            $this->addFlash('success', sprintf('Saved permission group "%s". Its users have the new access on their next page.', $name));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_permission_groups', ['_fragment' => 'group-'.$id]);
    }

    #[Route('/dashboard/users/permission-groups/{id}/delete', name: 'app_permission_groups_delete', methods: ['POST'], requirements: ['id' => '[a-z0-9][a-z0-9-]{0,62}'])]
    public function deletePermissionGroup(Request $request, string $id): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();
        if (!$this->isCsrfTokenValid('delete_permission_group_'.$id, (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_permission_groups');
        }
        $name = $this->permissionGroups->group($id)['name'] ?? $id;
        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $name);
        try {
            $this->permissionGroups->delete($id);
            $removed = $this->administration->forgetPermissionGroup($id);
            $this->addFlash('success', $removed > 0
                ? sprintf('Deleted permission group "%s" and removed it from %d user%s.', $name, $removed, $removed === 1 ? '' : 's')
                : sprintf('Deleted permission group "%s".', $name));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_permission_groups');
    }

    /** What the access fields need: pages, websites, website groups and permission groups. */
    private function formContext(): array
    {
        try {
            $websites = $this->websites->getWebsites();
        } catch (\Throwable) {
            $websites = [];
        }
        $websiteGroups = [];
        if (!$this->websiteGroups->hasLoadError()) {
            foreach ($this->websiteGroups->groups() as $groupId => $group) {
                $websiteGroups[$groupId] = $group['name'];
            }
        }

        $websites = array_values(array_filter($websites, static fn (array $website): bool => is_string($website['token'] ?? null) && $website['token'] !== ''));
        $names = [];
        foreach ($websites as $website) {
            $names[$website['token']] = (string) ($website['name'] ?? $website['token']);
        }

        return [
            'pages' => DashboardPages::PAGES,
            'websites' => $websites,
            'website_names' => $names,
            'website_groups' => $websiteGroups,
            'website_group_label' => $this->websiteGroups->hasLoadError() ? WebsiteScopeCatalog::DEFAULT_PLURAL : $this->websiteGroups->label()['plural'],
            'permission_groups' => $this->permissionGroups->groups(),
            'permission_groups_error' => $this->permissionGroups->loadError(),
        ];
    }

    /** @return array<string, int> */
    private function safeMemberCounts(): array
    {
        try {
            return $this->administration->permissionGroupMembers();
        } catch (\Throwable) {
            return [];
        }
    }

    private function findUser(int $id): User
    {
        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException('User not found.');
        }

        return $user;
    }

    /** A summary for the audit trail: the account type and what was granted, never a password or email. */
    private function auditDetails(bool $wasAdmin, bool $isAdmin, array $access): string
    {
        if ($wasAdmin !== $isAdmin) {
            $type = $isAdmin ? 'Made an administrator.' : 'Made a standard user.';
        } else {
            $type = $isAdmin ? 'Administrator account.' : 'Standard account.';
        }
        if ($isAdmin) {
            return $type;
        }
        $grant = AccessGrant::normalizeUser($access);
        $pages = [];
        foreach ($grant['pages'] as $page => $level) {
            $pages[] = DashboardPages::label($page).' ('.$level.')';
        }

        return trim(sprintf(
            '%s Permission groups: %s. Websites: %s. Pages: %s.',
            $type,
            $grant['permission_groups'] === [] ? 'none' : implode(', ', $grant['permission_groups']),
            $grant['websites']['all'] ? 'every website' : sprintf('%d group(s), %d website(s)', count($grant['websites']['groups']), count($grant['websites']['sites'])),
            $pages === [] ? 'none' : implode(', ', $pages),
        ));
    }
}
