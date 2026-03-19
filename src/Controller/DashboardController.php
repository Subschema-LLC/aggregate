<?php

namespace App\Controller;

use App\Repository\WebsiteRepository;
use App\Service\AggregateConfigLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    public function __construct(
        private readonly WebsiteRepository $websiteRepository,
        private readonly AggregateConfigLoader $config,
    ) {}

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        $websites = $this->websiteRepository->findAll();
        $appHost = $this->config->getWithEnvFallback('app_host', 'http://localhost');
        $jsNamespace = $this->config->getWithEnvFallback('js_namespace', 'Aggregate');

        return $this->render('dashboard/index.html.twig', [
            'websites' => $websites,
            'app_host' => $appHost,
            'js_namespace' => $jsNamespace,
        ]);
    }
}
