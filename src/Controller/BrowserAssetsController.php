<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\BrowserAssetBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BrowserAssetsController extends AbstractController
{
    public function __construct(private readonly AggregateConfigLoader $config, private readonly BrowserAssetBuilder $builder)
    {
    }

    #[Route('/dashboard/setup/build-scripts', name: 'app_setup_build_scripts', methods: ['POST'])]
    public function build(Request $request): Response
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $token = $request->request->all()['_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('build_browser_scripts', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        try {
            $this->builder->build();
            $this->addFlash('success', 'Browser scripts were minified. The installation snippets use the latest configured scripts.');
        } catch (\RuntimeException $error) {
            $this->addFlash('error', $error->getMessage());
        }

        $response = $this->redirectToRoute('app_setup', ['step' => 3], Response::HTTP_SEE_OTHER);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
