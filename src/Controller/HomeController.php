<?php

namespace App\Controller;

use App\Service\InstallationChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly InstallationChecker $installationChecker,
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        try {
            // If user is logged in, redirect to dashboard
            if ($this->getUser()) {
                return $this->redirectToRoute('app_dashboard');
            }

            // Check if installed
            if (!$this->installationChecker->isInstalled()) {
                return $this->redirectToRoute('app_install');
            }

            // Show login page
            return $this->redirectToRoute('app_login');
        } catch (\Exception $e) {
            // If anything fails, just redirect to install
            return $this->redirectToRoute('app_install');
        }
    }
}
