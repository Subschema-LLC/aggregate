<?php

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\BrandingLogoManager;
use App\Service\BrandingSettingsForm;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/** Branding: name, logo, colors and fonts of the dashboard, stored in YAML and the managed logo directory. */
class BrandingSettingsController extends AbstractController
{
    use DashboardAccess;

    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly BrandingLogoManager $brandingLogoManager,
        private readonly LoggerInterface $logger,
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
            $form = BrandingSettingsForm::fromSubmission($request->request, $request->files->get('brand_logo'), $this->config);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_branding_settings');
        }
        $uploadedLogo = $form['upload'];
        $removeLogo = $form['remove_logo'];
        $hasLogoMutation = $uploadedLogo instanceof UploadedFile || $removeLogo;

        $oldLogoPath = '';
        $newLogoPath = '';
        if ($hasLogoMutation) {
            $configuredPath = $this->config->get('brand_logo_path', '');
            $oldLogoPath = is_string($configuredPath) ? trim($configuredPath) : '';
            $newLogoPath = $removeLogo ? '' : $oldLogoPath;
        }
        $storedLogoPath = null;

        if ($uploadedLogo instanceof UploadedFile) {
            try {
                $storedLogoPath = $this->brandingLogoManager->store($uploadedLogo);
                $newLogoPath = $storedLogoPath;
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $e->getMessage());
                return $this->redirectToRoute('app_branding_settings');
            } catch (\Throwable $e) {
                $this->logger->error('Failed to store a branding logo.', ['exception' => $e]);
                $this->addFlash('error', 'The logo could not be stored. Check the application logs and var/branding permissions.');
                return $this->redirectToRoute('app_branding_settings');
            }
        }

        try {
            $settings = $form['settings'];
            if ($hasLogoMutation) {
                $settings['brand_logo_path'] = $newLogoPath;
            }
            if ($settings === []) {
                $this->addFlash('warning', 'No branding changes were submitted; environment-controlled values were left unchanged.');
                return $this->redirectToRoute('app_branding_settings');
            }
            $this->config->setMany($settings);
        } catch (\Throwable $e) {
            if ($storedLogoPath !== null) {
                try {
                    $this->brandingLogoManager->removeManaged($storedLogoPath);
                } catch (\Throwable $cleanupError) {
                    $this->logger->warning('Failed to remove an uncommitted branding logo.', ['exception' => $cleanupError]);
                }
            }

            $this->logger->error('Failed to save branding settings.', ['exception' => $e]);
            $this->addFlash('error', 'Branding settings could not be saved. Check the application logs.');
            return $this->redirectToRoute('app_branding_settings');
        }

        if ($hasLogoMutation && $oldLogoPath !== '' && $oldLogoPath !== $newLogoPath) {
            try {
                $this->brandingLogoManager->removeManaged($oldLogoPath);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to remove the previous managed branding logo.', ['exception' => $e]);
                $this->addFlash('warning', 'Branding was saved, but the previous managed logo could not be removed.');
            }
        }

        $this->addFlash('success', 'Branding updated successfully.');

        return $this->redirectToRoute('app_branding_settings');
    }
}
