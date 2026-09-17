<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\EventExampleGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

final class EventExamplesController extends AbstractController
{
    public function __construct(
        private readonly AggregateConfigLoader $config,
        private readonly EventExampleGenerator $examples,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/dashboard/data-model/examples', name: 'app_event_examples', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        return $this->renderExamples('model');
    }

    #[Route('/dashboard/data-model/examples/ecommerce', name: 'app_event_examples_ecommerce', methods: ['GET'])]
    public function ecommerce(): Response
    {
        $this->denyUnlessAvailableToAdmin();

        return $this->renderExamples('ecommerce');
    }

    private function renderExamples(string $example): Response
    {
        $bundle = null;
        $payloads = [];
        try {
            $bundle = $this->examples->generate($example);
            foreach ($bundle['examples'] as $mode => $requestExample) {
                $payloads[$mode] = json_encode($requestExample['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        } catch (\Throwable $error) {
            $this->logFailure($error);
            $bundle = null;
            $payloads = [];
        }

        return $this->privateResponse($this->render('event_examples/index.html.twig', [
            'bundle' => $bundle,
            'payloads' => $payloads,
            'example_source' => $example,
            'recommended_model_yaml' => isset($bundle['recommended_model']) ? Yaml::dump($bundle['recommended_model'], 5, 2) : null,
        ], new Response(status: $bundle === null ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK)));
    }

    #[Route('/dashboard/data-model/examples/download', name: 'app_event_examples_download', methods: ['GET'])]
    public function download(Request $request): Response
    {
        $this->denyUnlessAvailableToAdmin();
        $mode = $request->query->all()['mode'] ?? 'all';
        $example = $request->query->all()['example'] ?? 'model';
        if (!is_string($mode) || !in_array($mode, ['all', 'anonymous', 'enhanced'], true)) {
            return $this->privateResponse(new JsonResponse(['error' => 'Choose all, anonymous, or enhanced for the example mode.'], Response::HTTP_BAD_REQUEST));
        }
        if (!is_string($example) || !in_array($example, ['model', 'ecommerce'], true)) {
            return $this->privateResponse(new JsonResponse(['error' => 'Choose model or ecommerce for the example source.'], Response::HTTP_BAD_REQUEST));
        }

        try {
            $json = $this->examples->exportJson($mode, $example);
        } catch (\Throwable $error) {
            $this->logFailure($error);

            return $this->privateResponse(new JsonResponse(['error' => 'Saved event examples are unavailable. Check the active data model configuration.'], Response::HTTP_SERVICE_UNAVAILABLE));
        }

        $prefix = $example === 'ecommerce' ? 'aggregate-ecommerce-examples' : 'aggregate-event-examples';
        $filename = $mode === 'all' ? $prefix.'.json' : $prefix.'-'.$mode.'.json';

        return $this->privateResponse(new Response($json, headers: [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]));
    }

    private function denyUnlessAvailableToAdmin(): void
    {
        if (!$this->config->isDashboardEnabled()) {
            throw $this->createNotFoundException('Dashboard is disabled.');
        }
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function logFailure(\Throwable $error): void
    {
        $this->logger->error('Event examples could not be generated.', ['exception_class' => $error::class]);
    }
}
