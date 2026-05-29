<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AggregateConfigLoader;
use App\Service\WebsiteConfigManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly WebsiteConfigManager $websiteManager,
        private readonly AggregateConfigLoader $config,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        $this->denyIfDashboardDisabled();

        $websites = $this->websiteManager->getWebsites();
        $appHost = $this->config->getWithEnvFallback('app_host', 'http://localhost:8000');
        $jsNamespace = $this->config->getWithEnvFallback('js_namespace', 'Aggregate');
        $rateLimit = $this->config->getWithEnvFallback('rate_limit_per_minute', 100);
        $users = $this->isGranted('ROLE_ADMIN') ? $this->userRepository->findBy([], ['createdAt' => 'ASC']) : [];

        return $this->render('dashboard/index.html.twig', [
            'websites' => $websites,
            'app_host' => $appHost,
            'js_namespace' => $jsNamespace,
            'rate_limit' => $rateLimit,
            'users' => $users,
        ]);
    }

    #[Route('/dashboard/website/create', name: 'app_website_create', methods: ['POST'])]
    public function createWebsite(Request $request): Response
    {
        $this->denyIfDashboardDisabled();

        $name = trim($request->request->get('name', ''));
        $domain = trim($request->request->get('domain', ''));

        // Validate
        if (empty($name)) {
            $this->addFlash('error', 'Website name is required');
            return $this->redirectToRoute('app_dashboard');
        }

        if (empty($domain)) {
            $this->addFlash('error', 'Domain is required');
            return $this->redirectToRoute('app_dashboard');
        }

        // Normalize domain (remove protocol, trailing slash)
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        try {
            $success = $this->websiteManager->addWebsite($name, $domain);

            if ($success) {
                $this->addFlash('success', "Website '{$name}' created successfully!");
            } else {
                $this->addFlash('error', 'Failed to save to config/websites.yaml');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create website: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/website/delete/{token}', name: 'app_website_delete', methods: ['POST'])]
    public function deleteWebsite(string $token): Response
    {
        $this->denyIfDashboardDisabled();

        try {
            $success = $this->websiteManager->removeWebsite($token);

            if ($success) {
                $this->addFlash('success', 'Website deleted successfully!');
            } else {
                $this->addFlash('error', 'Website not found or could not be deleted.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to delete website: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/settings/save', name: 'app_settings_save', methods: ['POST'])]
    public function saveSettings(Request $request): Response
    {
        $this->denyIfDashboardDisabled();

        $appHost = trim($request->request->get('app_host', ''));
        $jsNamespace = trim($request->request->get('js_namespace', 'Aggregate'));
        $rateLimit = (int) $request->request->get('rate_limit', 100);

        if (empty($appHost)) {
            $this->addFlash('error', 'App Host is required.');
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $this->config->set('app_host', $appHost);
            $this->config->set('js_namespace', $jsNamespace);
            $this->config->set('rate_limit_per_minute', $rateLimit);

            $this->addFlash('success', 'Settings updated successfully in config/aggregate.yaml!');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to save settings: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/users/create', name: 'app_user_create', methods: ['POST'])]
    public function createUser(Request $request): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('create_user', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        $username = trim((string) $request->request->get('username', ''));
        $password = (string) $request->request->get('password', '');
        $isAdmin = $request->request->getBoolean('is_admin');

        if ($username === '' || $password === '') {
            $this->addFlash('error', 'Username and password are required.');
            return $this->redirectToRoute('app_dashboard');
        }

        if (strlen($username) > 180) {
            $this->addFlash('error', 'Username must be 180 characters or fewer.');
            return $this->redirectToRoute('app_dashboard');
        }

        if (strlen($password) < 8) {
            $this->addFlash('error', 'Password must be at least 8 characters.');
            return $this->redirectToRoute('app_dashboard');
        }

        if ($this->userRepository->findOneBy(['username' => $username]) instanceof User) {
            $this->addFlash('error', sprintf('User "%s" already exists.', $username));
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $user = new User();
            $user->setUsername($username);
            $user->setRoles($isAdmin ? ['ROLE_ADMIN', 'ROLE_USER'] : ['ROLE_USER']);
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));

            $this->em->persist($user);
            $this->em->flush();

            $this->addFlash('success', sprintf('User "%s" created successfully.', $username));
        } catch (UniqueConstraintViolationException) {
            $this->addFlash('error', sprintf('User "%s" already exists.', $username));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create user: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/users/{id}/password', name: 'app_user_password_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUserPassword(Request $request, int $id): Response
    {
        $this->denyIfDashboardDisabled();
        $this->denyIfNotAdmin();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('update_user_password_' . $id, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_dashboard');
        }

        $newPassword = (string) $request->request->get('new_password', '');
        if ($newPassword === '') {
            $this->addFlash('error', 'New password is required.');
            return $this->redirectToRoute('app_dashboard');
        }

        if (strlen($newPassword) < 8) {
            $this->addFlash('error', 'New password must be at least 8 characters.');
            return $this->redirectToRoute('app_dashboard');
        }

        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
            $this->em->flush();

            $this->addFlash('success', sprintf('Password updated for "%s".', $user->getUsername()));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update password: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }

    private function denyIfDashboardDisabled(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
    }

    private function denyIfNotAdmin(): void
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Only administrators can manage users.');
        }
    }
}
