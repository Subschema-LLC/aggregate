<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\DocumentationLinks;
use App\Service\FeatureFlags;
use App\Service\ReportingApi\ApiProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers errors under /api/v1 that the reporting API controller never sees,
 * such as an unknown address or a POST, as problem details like every other
 * API error. While the reporting_api flag is off it does nothing, so those
 * addresses get the application's ordinary 404 and do not reveal the API.
 */
final class ReportingApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly FeatureFlags $features,
        private readonly DocumentationLinks $documentation,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the framework's error listener renders a page.
        return [KernelEvents::EXCEPTION => ['onException', 16]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if ($path !== '/api/v1' && !str_starts_with($path, '/api/v1/')) {
            return;
        }
        if (!$this->features->isEnabled('reporting_api')) {
            return;
        }
        $error = $event->getThrowable();
        $problem = match (true) {
            $error instanceof ApiProblem => $error,
            $error instanceof NotFoundHttpException => new ApiProblem(404, 'not_found', 'There is nothing at this address. GET /api/v1/views lists the views this token may read.'),
            $error instanceof MethodNotAllowedHttpException => new ApiProblem(405, 'method_not_allowed', 'The reporting API is read-only: use GET.', ['Allow' => 'GET, HEAD']),
            $error instanceof HttpExceptionInterface && $error->getStatusCode() < 500 => new ApiProblem($error->getStatusCode(), 'invalid_parameter', 'The request could not be understood.'),
            default => new ApiProblem(500, 'internal_error', 'The request could not be completed.'),
        };
        $response = $problem->toResponse($this->documentation->url('reporting.api-errors'));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $event->setResponse($response);
    }
}
