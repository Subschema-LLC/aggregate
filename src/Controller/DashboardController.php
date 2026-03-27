<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\WebsiteConfigManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly WebsiteConfigManager $websiteManager,
        private readonly EntityManagerInterface $em,
        private readonly AggregateConfigLoader $config,
        private readonly KernelInterface $kernel,
    ) {}

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        $websites = $this->websiteManager->getWebsites();
        $appHost = $this->config->getWithEnvFallback('app_host', 'http://localhost:8000');
        $jsNamespace = $this->config->getWithEnvFallback('js_namespace', 'Aggregate');
        $rateLimit = $this->config->getWithEnvFallback('rate_limit_per_minute', 100);

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
            'rate_limit' => $rateLimit,
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

    #[Route('/dashboard/settings/save', name: 'app_settings_save', methods: ['POST'])]
    public function saveSettings(Request $request): Response
    {
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
}
