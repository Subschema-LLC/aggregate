<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\WebsiteConfigManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly WebsiteConfigManager $websiteManager,
        private readonly AggregateConfigLoader $config,
        private readonly KernelInterface $kernel,
    ) {}

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        $websites = $this->websiteManager->getWebsites();
        $appHost = $this->config->getWithEnvFallback('app_host', 'http://localhost:8000');
        $jsNamespace = $this->config->getWithEnvFallback('js_namespace', 'Aggregate');

        // Check if worker is running
        $pidFile = $this->kernel->getProjectDir() . '/var/worker.pid';
        $workerRunning = false;
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            $workerRunning = $pid && file_exists("/proc/$pid");
        }

        return $this->render('dashboard/index.html.twig', [
            'websites' => $websites,
            'app_host' => $appHost,
            'js_namespace' => $jsNamespace,
            'worker_running' => $workerRunning,
        ]);
    }

    #[Route('/dashboard/website/create', name: 'app_website_create', methods: ['POST'])]
    public function createWebsite(Request $request): Response
    {
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
                $this->addFlash('success', "Website '{$name}' created successfully! Check config/websites.yaml");
            } else {
                $this->addFlash('error', 'Failed to write to config/websites.yaml. Please check file permissions.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create website: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/dashboard/website/delete/{token}', name: 'app_website_delete', methods: ['POST'])]
    public function deleteWebsite(string $token): Response
    {
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

    #[Route('/dashboard/worker/restart', name: 'app_worker_restart', methods: ['POST'])]
    public function restartWorker(): Response
    {
        $projectDir = $this->kernel->getProjectDir();
        $pidFile = $projectDir . '/var/worker.pid';

        try {
            // Kill existing worker if running
            if (file_exists($pidFile)) {
                $pid = trim(file_get_contents($pidFile));
                if ($pid && file_exists("/proc/$pid")) {
                    shell_exec("kill $pid");
                    usleep(500000); // Wait 0.5s for graceful shutdown
                }
                unlink($pidFile);
            }

            // Start new worker
            $workerCommand = sprintf(
                'nohup php %s/bin/console messenger:consume async --time-limit=3600 --memory-limit=128M > %s/var/log/worker.log 2>&1 & echo $!',
                $projectDir,
                $projectDir
            );

            $pid = shell_exec($workerCommand);
            if ($pid) {
                file_put_contents($pidFile, trim($pid));
                $this->addFlash('success', 'Worker restarted successfully!');
            } else {
                $this->addFlash('error', 'Failed to start worker.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to restart worker: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
