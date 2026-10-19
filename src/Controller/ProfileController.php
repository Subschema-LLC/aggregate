<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\EventSubscriber\AdminAuditSubscriber;
use App\Security\Access\UserAdministration;
use App\Service\AggregateConfigLoader;
use App\Service\WebsiteConfigManager;
use App\Service\WebsiteScopeCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every signed-in user's own profile: their display name, email and password,
 * and a read-only list of what they can use and where each grant comes from.
 */
final class ProfileController extends AbstractController
{
    use DashboardAccess;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly UserAdministration $administration,
        private readonly WebsiteConfigManager $websites,
        private readonly WebsiteScopeCatalog $websiteGroups,
    ) {
    }

    #[Route('/dashboard/profile', name: 'app_profile', methods: ['GET'])]
    public function profile(): Response
    {
        $this->denyUnlessPermitted();
        $user = $this->currentUser();
        try {
            $websites = $this->websites->getWebsites();
        } catch (\Throwable) {
            $websites = [];
        }
        $names = [];
        foreach ($websites as $website) {
            if (is_string($website['token'] ?? null)) {
                $domain = (string) ($website['domain'] ?? '');
                $names[$website['token']] = (string) ($website['name'] ?? $website['token']).($domain !== '' ? ' ('.$domain.')' : '');
            }
        }
        $groups = [];
        if (!$this->websiteGroups->hasLoadError()) {
            foreach ($this->websiteGroups->groups() as $id => $group) {
                $count = count($group['members']);
                $groups[$id] = sprintf('%s (%d website%s)', $group['name'], $count, $count === 1 ? '' : 's');
            }
        }

        return $this->renderDashboardPage('users/profile.html.twig', [
            'profile' => $user,
            'website_names' => $names,
            'group_names' => $groups,
            'website_group_label' => $this->websiteGroups->hasLoadError() ? WebsiteScopeCatalog::DEFAULT_PLURAL : $this->websiteGroups->label()['plural'],
        ]);
    }

    #[Route('/dashboard/profile', name: 'app_profile_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('profile', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_profile');
        }
        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $user->getUsername());
        try {
            $this->administration->updateProfile($user, (string) $request->request->get('display_name', ''), (string) $request->request->get('email', ''));
            $this->addFlash('success', 'Profile saved.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Your profile could not be saved. Try again later.');
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/dashboard/profile/password', name: 'app_profile_password', methods: ['POST'])]
    public function password(Request $request): Response
    {
        $this->denyUnlessPermitted();
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('profile_password', (string) $request->request->get('_csrf_token', ''))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirectToRoute('app_profile');
        }
        $request->attributes->set(AdminAuditSubscriber::SUBJECT, $user->getUsername());
        $request->attributes->set(AdminAuditSubscriber::DETAILS, 'Changed their own password.');
        $new = (string) $request->request->get('new_password', '');
        try {
            if (!$this->administration->isCurrentPassword($user, (string) $request->request->get('current_password', ''))) {
                throw new \InvalidArgumentException('Your current password is not correct.');
            }
            if ($new !== (string) $request->request->get('confirm_password', '')) {
                throw new \InvalidArgumentException('The new passwords do not match.');
            }
            // This session stays signed in; other sessions of this account sign out.
            $this->administration->changePassword($user, $new);
            $this->addFlash('success', 'Password changed. Your other sessions are signed out.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Your password could not be changed. Try again later.');
        }

        return $this->redirectToRoute('app_profile');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Sign in to see your profile.');
        }

        return $user;
    }
}
