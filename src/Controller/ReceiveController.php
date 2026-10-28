<?php

namespace App\Controller;

use App\Service\EventIngestion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * The collection endpoint. EventIngestion decides what is recorded; this
 * controller only answers, with CORS headers for the website that sent it.
 */
class ReceiveController
{
    #[Route('/api/receive', name: 'api_receive', methods: ['POST'])]
    public function __invoke(Request $request, EventIngestion $ingestion): Response
    {
        $result = $ingestion->receive($request);

        return $this->jsonWithCors($request, $result->body, $result->status);
    }

    #[Route('/api/receive', name: 'api_receive_options', methods: ['OPTIONS'])]
    public function options(Request $request): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->applyCorsHeaders($response, $request);

        return $response;
    }

    private function jsonWithCors(Request $request, array $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $this->applyCorsHeaders($response, $request);

        return $response;
    }

    private function applyCorsHeaders(Response $response, Request $request): void
    {
        $origin = $request->headers->get('Origin');
        if (!$origin) {
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
        $response->headers->set('Access-Control-Max-Age', '86400');
    }
}
