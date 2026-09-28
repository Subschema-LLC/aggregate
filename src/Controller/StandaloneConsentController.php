<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\SiteScriptConfig;
use App\Service\StandaloneConsentSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class StandaloneConsentController extends AbstractController
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly SiteScriptConfig $sites,
        private readonly StandaloneConsentSettings $settings,
    ) {
    }

    #[Route('/dashboard/setup/standalone/{siteId}', name: 'app_standalone_consent', requirements: ['siteId' => '[a-f0-9]{24}'], methods: ['GET', 'POST'])]
    public function index(Request $request, string $siteId): Response
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        try {
            $site = $this->sites->site($siteId);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Choose a registered website.');
        }
        $error = null;
        $yaml = '';
        $status = 200;
        if ($request->isMethod('POST')) {
            $form = $request->request->all();
            $token = $form['_token'] ?? null;
            if (!is_string($token) || !$this->isCsrfTokenValid('standalone_consent_'.$siteId, $token)) {
                throw $this->createAccessDeniedException('Invalid security token.');
            }
            $yaml = is_string($form['yaml'] ?? null) ? $form['yaml'] : '';
            try {
                if (strlen($yaml) > 16384) {
                    throw new \InvalidArgumentException('Standalone consent YAML must be at most 16 KiB.');
                }
                $parsed = Yaml::parse($yaml);
                if (!is_array($parsed) || array_keys($parsed) !== ['standalone_consent']) {
                    throw new \InvalidArgumentException('Supply only the standalone_consent mapping.');
                }
                $this->settings->save($siteId, $parsed['standalone_consent']);
                $this->addFlash('success', 'Standalone consent settings saved. Hosted scripts update on the next page load; replace downloaded snapshots separately.');

                return $this->redirectToRoute('app_standalone_consent', ['siteId' => $siteId]);
            } catch (\InvalidArgumentException|ParseException $e) {
                $error = $e->getMessage();
                $status = 422;
            } catch (\Throwable) {
                $error = 'Settings could not be saved. Check the active YAML and file permissions.';
                $status = 503;
            }
        } else {
            try {
                $yaml = $this->settings->exportYaml($siteId);
            } catch (\Throwable) {
                $error = 'Standalone settings could not be loaded. Correct the active YAML before saving.';
                $status = 503;
            }
        }

        $response = $this->render('setup/standalone.html.twig', ['site' => $site, 'yaml' => $yaml, 'error' => $error]);
        $response->setStatusCode($status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
