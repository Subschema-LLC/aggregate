<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\BrandingTheme;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

final class BrandingThemeController
{
    public function __construct(
        private readonly BrandingTheme $theme,
        private readonly Environment $twig,
    ) {}

    #[Route('/branding/theme.css', name: 'app_branding_theme', methods: ['GET'], stateless: true)]
    public function __invoke(Request $request): Response
    {
        $css = $this->twig->render('branding/theme.css.twig', ['theme' => $this->theme->toArray()]);
        $response = new Response($css, Response::HTTP_OK, [
            // Revalidate on every page load so saved YAML and environment changes
            // take effect without rebuilding the static application stylesheets.
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag(hash('sha256', $css));
        $response->isNotModified($request);

        return $response;
    }
}
