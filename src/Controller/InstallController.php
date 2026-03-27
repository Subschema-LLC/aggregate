<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AggregateConfigLoader;
use App\Service\InstallationChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class InstallController extends AbstractController
{
    public function __construct(
        private readonly InstallationChecker $installationChecker,
        private readonly AggregateConfigLoader $config,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly KernelInterface $kernel,
    ) {}

    #[Route('/install', name: 'app_install')]
    public function install(): Response
    {
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
        if ($this->installationChecker->isInstalled()) {
            return $this->redirectToRoute('app_home');
        }

        if (!$this->installationChecker->isConfigValid()) {
            $this->addFlash('error', 'Configuration is invalid. Please fix config/aggregate.yaml');
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
            // Auto-generate and persist daily_salt_secret if not already set
            if (empty($this->config->getWithEnvFallback('daily_salt_secret', null))) {
                $this->config->set('daily_salt_secret', base64_encode(random_bytes(32)));
            }

            // Persist js_namespace
            $this->config->set('js_namespace', $jsNamespace);

            // Run migrations
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $output = new BufferedOutput();
            $result = $application->run(new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
            ]), $output);

            if ($result !== 0) {
                $this->addFlash('error', 'Migration failed: ' . $output->fetch());
                return $this->redirectToRoute('app_install');
            }

            // Create admin user
            $user = new User();
            $user->setUsername($adminUsername);
            $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
            $user->setPassword($this->passwordHasher->hashPassword($user, $adminPassword));

            $this->em->persist($user);
            $this->em->flush();

            // Mark as installed in config
            $this->config->set('installed', true);

            // Start background worker
            $workerCommand = sprintf(
                'nohup php %s/bin/console messenger:consume async --time-limit=3600 --memory-limit=128M > %s/var/log/worker.log 2>&1 & echo $!',
                $this->kernel->getProjectDir(),
                $this->kernel->getProjectDir()
            );

            $pid = shell_exec($workerCommand);
            if ($pid) {
                file_put_contents($this->kernel->getProjectDir() . '/var/worker.pid', trim($pid));
            }

            $this->addFlash('success', 'Installation completed! Please log in.');
            return $this->redirectToRoute('app_login');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Installation failed: ' . $e->getMessage());
            return $this->redirectToRoute('app_install');
        }
    }
}
