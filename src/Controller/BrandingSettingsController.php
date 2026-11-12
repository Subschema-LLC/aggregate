<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\BrandingSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/** Branding: name, logo, colors and fonts of the dashboard, stored in YAML and the managed logo directory. */
class BrandingSettingsController extends AbstractController
{
    use DashboardAccess;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly BrandingSettingsService $branding,
    ) {}

    #[Route('/dashboard/branding', name: 'app_branding_settings', methods: ['GET'])]
    public function brandingSettings(): Response
    {
        $this->denyUnlessPermitted();

        return $this->renderDashboardPage('settings/branding.html.twig');
    }

    #[Route('/dashboard/settings/branding', name: 'app_branding_settings_save', methods: ['POST'])]
    public function saveBrandingSettings(Request $request): Response
    {
        $this->denyUnlessPermitted();

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('branding_settings', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirectToRoute('app_branding_settings');
        }

        try {
            $result = $this->branding->save($request->request, $request->files->get('brand_logo'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_branding_settings');
        }
        foreach ($result['warnings'] as $warning) {
            $this->addFlash('warning', $warning);
        }
        if (!$result['saved']) {
            return $this->redirectToRoute('app_branding_settings');
        }

        $this->addFlash('success', 'Branding updated successfully.');

        return $this->redirectToRoute('app_branding_settings');
    }
}
