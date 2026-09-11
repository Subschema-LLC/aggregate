<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventSubscriber\InternalTrafficResponseSubscriber;
use App\Service\InternalTrafficSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class InternalTrafficPublicController extends AbstractController
{
    public function __construct(private readonly InternalTrafficSettings $settings)
    {
    }

    #[Route('/internal-traffic/{token}', name: 'app_internal_traffic_public', defaults: ['token' => ''], methods: ['GET'])]
    public function index(Request $request, string $token = ''): Response
    {
        if (!$this->settings->matchesShareToken($token)) {
            return $this->unavailable();
        }
        try {
            $browserConfig = $this->settings->toBrowserConfig();
        } catch (\Throwable) {
            return $this->unavailable();
        }

        $download = $request->query->get('download') === '1';
        $response = $this->render('internal_traffic/public.html.twig', [
            'browser_config' => $browserConfig,
            'download_url' => $download ? null : $this->generateUrl('app_internal_traffic_public', ['token' => $token, 'download' => '1']),
        ]);
        if ($download) {
            $response->headers->set('Content-Disposition', 'attachment; filename="internal-traffic.html"');
        }

        return InternalTrafficResponseSubscriber::protect($response);
    }

    private function unavailable(): Response
    {
        return InternalTrafficResponseSubscriber::protect(new Response(
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="referrer" content="no-referrer"><title>Page not found</title></head><body><h1>Page not found</h1></body></html>',
            Response::HTTP_NOT_FOUND,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        ));
    }
}
