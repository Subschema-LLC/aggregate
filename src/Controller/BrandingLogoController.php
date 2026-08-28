<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AppBranding;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

final class BrandingLogoController
{
    public function __construct(
        private readonly AppBranding $branding,
    ) {}

    #[Route('/branding/logo', name: 'app_branding_logo', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $logo = $this->branding->getValidatedLogo();
        if ($logo === null) {
            return $this->notFoundResponse();
        }

        $response = new Response(
            content: '',
            status: Response::HTTP_OK,
            headers: [
                'Cache-Control' => 'public, max-age=3600, must-revalidate',
                'Content-Type' => $logo['mime_type'],
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $this->downloadName($logo['mime_type']),
        ));
        $response->setEtag($logo['version']);
        if ($logo['last_modified'] !== null) {
            $response->setLastModified((new \DateTimeImmutable())->setTimestamp($logo['last_modified']));
        }
        if ($response->isNotModified($request)) {
            return $response;
        }

        $response->setContent($logo['content']);
        $response->headers->set('Content-Length', (string) strlen($logo['content']));

        return $response;
    }

    private function notFoundResponse(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, [
            'Cache-Control' => 'no-store',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function downloadName(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'brand-logo.jpg',
            'image/webp' => 'brand-logo.webp',
            default => 'brand-logo.png',
        };
    }
}
