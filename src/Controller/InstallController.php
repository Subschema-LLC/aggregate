<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AggregateConfigLoader;
use App\Service\InstallationChecker;
use App\Service\InternalTrafficSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class InstallController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'install';

    public function __construct(
        private readonly InstallationChecker $installationChecker,
        private readonly AggregateConfigLoader $config,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly KernelInterface $kernel,
        private readonly InternalTrafficSettings $internalTrafficSettings,
    ) {}

    #[Route('/install', name: 'app_install', methods: ['GET'])]
    public function install(): Response
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }

        if ($this->installationChecker->isInstalled()) {
            return $this->redirectToRoute('app_home');
        }

        if (!$this->installationChecker->isConfigValid()) {
            return $this->render('install/config_error.html.twig', [
                'errors' => $this->installationChecker->getConfigErrors(),
            ]);
        }

        return $this->render('install/index.html.twig');
    }

    #[Route('/install/execute', name: 'app_install_execute', methods: ['POST'])]
    public function executeInstall(Request $request): Response
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }

        if ($this->installationChecker->isInstalled()) {
            return $this->redirectToRoute('app_home');
        }

        $csrfToken = $request->request->all()['_csrf_token'] ?? null;
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $csrfToken)) {
            throw new AccessDeniedHttpException('Invalid installation CSRF token.');
        }

        if (!$this->installationChecker->isConfigValid()) {
            $this->addFlash('error', 'Configuration is invalid. Please fix your .env/.env.local values.');
            return $this->redirectToRoute('app_install');
        }

        $adminUsername = trim($request->request->get('admin_username', ''));
        $adminPassword = trim($request->request->get('admin_password', ''));
        $jsNamespace   = trim($request->request->get('js_namespace', 'Aggregate')) ?: 'Aggregate';

        if (empty($adminUsername) || empty($adminPassword)) {
            $this->addFlash('error', 'Admin username and password are required.');
            return $this->redirectToRoute('app_install');
        }

        if (strlen($adminPassword) < 8) {
            $this->addFlash('error', 'Admin password must be at least 8 characters.');
            return $this->redirectToRoute('app_install');
        }

        try {
            // Persist js_namespace
            $this->config->set('js_namespace', $jsNamespace);

            // Run migrations
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $result = $application->run(new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
            ]), new NullOutput());

            if ($result !== 0) {
                $this->addFlash('error', 'Migration failed. Run php bin/console doctrine:migrations:migrate on the server for diagnostics.');
                return $this->redirectToRoute('app_install');
            }

            // Glossary metadata is populated after migrations, never during kernel boot.
            $glossaryResult = $application->run(new ArrayInput([
                'command' => 'app:analytics:glossary:sync',
                '--no-interaction' => true,
            ]), new NullOutput());

            if ($glossaryResult !== 0) {
                $this->addFlash('warning', 'Database setup completed, but the BI glossary was not updated. Run php bin/console app:analytics:glossary:sync on the server for diagnostics.');
            }

            $this->internalTrafficSettings->ensureShareToken();

            // Create admin user
            $user = new User();
            $user->setUsername($adminUsername);
            $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
            $user->setPassword($this->passwordHasher->hashPassword($user, $adminPassword));

            $this->em->persist($user);
            $this->em->flush();

            // Mark as installed in config
            $this->config->set('installed', true);

            $this->addFlash('success', 'Installation completed. Log in now. Configure a worker later only if you switch to async ingestion mode.');
            return $this->redirectToRoute('app_login');
        } catch (\Exception) {
            $this->addFlash('error', 'Installation failed. Run php bin/console app:install on the server for diagnostics.');
            return $this->redirectToRoute('app_install');
        }
    }
}
