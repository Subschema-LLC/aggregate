<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AggregateConfigLoader;
use App\Service\InstallationChecker;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
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
        // If already installed, redirect to home
        if ($this->installationChecker->isInstalled()) {
            return $this->redirectToRoute('app_home');
        }

        // Check if config is valid
        if (!$this->installationChecker->isConfigValid()) {
            $errors = $this->installationChecker->getConfigErrors();
            return $this->render('install/config_error.html.twig', [
                'errors' => $errors,
            ]);
        }

        return $this->render('install/index.html.twig');
    }

    #[Route('/install/execute', name: 'app_install_execute')]
    public function executeInstall(): Response
    {
        // If already installed, redirect
        if ($this->installationChecker->isInstalled()) {
            return $this->redirectToRoute('app_home');
        }

        // Check config validity
        if (!$this->installationChecker->isConfigValid()) {
            $this->addFlash('error', 'Configuration is invalid. Please fix config/aggregate.yaml');
            return $this->redirectToRoute('app_install');
        }

        try {
            // Run migrations first
            $application = new Application($this->kernel);
            $application->setAutoExit(false);

            $input = new ArrayInput([
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
            ]);

            $output = new BufferedOutput();
            $migrationResult = $application->run($input, $output);

            if ($migrationResult !== 0) {
                $this->addFlash('error', 'Migration failed: ' . $output->fetch());
                return $this->redirectToRoute('app_install');
            }

            // Get credentials from config
            $adminUsername = $this->config->getWithEnvFallback('admin_username', 'admin');
            $adminPassword = $this->config->getWithEnvFallback('admin_password', 'changeme');

            // Create admin user
            $user = new User();
            $user->setUsername($adminUsername);
            $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);

            $hashedPassword = $this->passwordHasher->hashPassword($user, $adminPassword);
            $user->setPassword($hashedPassword);

            $this->em->persist($user);
            $this->em->flush();

            // Start the messenger worker in the background
            $workerCommand = sprintf(
                'nohup php %s/bin/console messenger:consume async --time-limit=3600 --memory-limit=128M > %s/var/log/worker.log 2>&1 & echo $!',
                $this->kernel->getProjectDir(),
                $this->kernel->getProjectDir()
            );

            $pid = shell_exec($workerCommand);
            if ($pid) {
                file_put_contents($this->kernel->getProjectDir() . '/var/worker.pid', trim($pid));
            }

            $this->addFlash('success', 'Installation completed successfully! Worker started. Please log in.');
            return $this->redirectToRoute('app_login');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Installation failed: ' . $e->getMessage());
            return $this->redirectToRoute('app_install');
        }
    }
}
