<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\InstallationChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly InstallationChecker $installationChecker,
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        if (!$this->config->isDashboardEnabled()) {
            return $this->render('home/index.html.twig', [
                'dashboard_enabled' => false,
                'is_installed' => false,
            ]);
        }

        // First-time app load goes straight to installer.
        if (!$this->installationChecker->isInstalled()) {
            return $this->redirectToRoute('app_install');
        }

        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->redirectToRoute('app_login');
    }

    #[Route('/how-it-works', name: 'app_how_it_works', methods: ['GET'])]
    public function howItWorks(): Response
    {
        return $this->render('home/how_it_works.html.twig');
    }

    #[Route('/how-it-works/data-visualization', name: 'app_how_it_works_data_visualization', methods: ['GET'])]
    public function dataVisualization(): Response
    {
        return $this->render('home/data_structure.html.twig');
    }
}
