<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DocumentationLinks;
use App\Service\FeatureFlags;
use App\Service\ReportingApi\ApiProblem;
use App\Service\ReportingApi\ApiRequestGuard;
use App\Service\ReportingApi\ApiToken;
use App\Service\ReportingApi\OpenApiDocument;
use App\Service\ReportingApi\ReportingApiSettings;
use App\Service\ReportingApi\ViewQuery;
use App\Service\ReportingApi\ViewQueryRunner;
use App\Service\ReportingApi\ViewSchema;
use App\Service\ViewSync\ViewCatalog;
use App\Service\ViewSync\ViewSyncException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * The read-only reporting API (docs/REPORTING-API.md): the views a token may
 * read, a view's columns, a view's rows, and the OpenAPI document. Every
 * endpoint needs a bearer token; the reporting_api feature flag decides
 * whether the API exists at all, and while it is off every address here is
 * an ordinary 404.
 *
 * Rows are streamed: the data array is written as the database returns rows,
 * and meta and links follow it, so a large page costs no server memory. The
 * query runs, and its first row is read, before the response starts, so a
 * failing query is still answered with a problem response. A response that
 * stops before its closing brace was interrupted and is incomplete.
 */
final class ReportingApiController
{
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly FeatureFlags $features,
        private readonly ApiRequestGuard $guard,
        private readonly ViewSchema $schema,
        private readonly ViewQueryRunner $runner,
        private readonly ReportingApiSettings $settings,
        private readonly OpenApiDocument $openApi,
        private readonly DocumentationLinks $documentation,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/v1/views', name: 'api_v1_views', methods: ['GET'])]
    public function views(Request $request): Response
    {
        return $this->answer(function () use ($request): Response {
            $token = $this->guard->authenticate($request);
            $privateAllowed = $this->settings->privateViewsAllowed();
            $data = [];
            foreach ($token->readableViews($privateAllowed) as $view) {
                $data[] = [
                    'name' => $view,
                    'description' => ViewCatalog::describe($view),
                    'approved' => ViewCatalog::isApproved($view),
                    'links' => ['rows' => $this->url($request, '/api/v1/views/'.$view), 'schema' => $this->url($request, '/api/v1/views/'.$view.'/schema')],
                ];
            }

            return $this->json(['data' => $data, 'meta' => ['private_views_allowed' => $privateAllowed, 'reporting_timezone' => $this->reportingTimezone()]]);
        });
    }

    #[Route('/api/v1/views/{view}/schema', name: 'api_v1_view_schema', requirements: ['view' => '[A-Za-z0-9_]{1,128}'], methods: ['GET'])]
    public function viewSchema(Request $request, string $view): Response
    {
        return $this->answer(function () use ($request, $view): Response {
            $this->readable($request, $view);
            if ($request->query->count() > 0) {
                throw ApiProblem::badRequest('unknown_parameter', 'This endpoint takes no parameters.');
            }
            $columns = $this->schema->columns($view);

            return $this->json(['data' => [
                'name' => $view,
                'description' => ViewCatalog::describe($view),
                'approved' => ViewCatalog::isApproved($view),
                'unique_order' => ViewSchema::uniqueOrder($view, array_column($columns, 'name')),
                'columns' => array_map(static fn (array $column): array => [
                    'name' => $column['name'],
                    'type' => $column['type'],
                    'label' => $column['label'],
                    'description' => $column['description'],
                    'timezone' => $column['timezone'],
                    'filter_operators' => $column['operators'],
                    'values' => $column['values'],
                    'values_truncated' => $column['values_truncated'],
                ], $columns),
            ]]);
        });
    }

    #[Route('/api/v1/views/{view}', name: 'api_v1_view_rows', requirements: ['view' => '[A-Za-z0-9_]{1,128}'], methods: ['GET'])]
    public function rows(Request $request, string $view): Response
    {
        return $this->answer(function () use ($request, $view): Response {
            $this->readable($request, $view);
            $columns = $this->schema->columns($view);
            try {
                $defaultLimit = $this->settings->defaultPageSize();
                $maxLimit = $this->settings->maxPageSize();
            } catch (\InvalidArgumentException|\RuntimeException $error) {
                throw new ApiProblem(503, 'configuration_invalid', 'The reporting API settings on this installation are invalid. An administrator needs to correct them.', previous: $error);
            }
            $query = ViewQuery::parse($view, $request->query->all(), $columns, $defaultLimit, $maxLimit);
            $types = array_column($columns, 'catalog_type', 'name');
            $timezone = $this->reportingTimezone();

            $rows = $this->runner->rows($query, $types, ViewSchema::uniqueOrder($view, array_keys($types)));
            try {
                // Runs the query and reads the first row before anything is sent.
                $rows->current();
            } catch (ViewSyncException $error) {
                throw new ApiProblem(503, 'view_unavailable', $error->getMessage(), previous: $error);
            } catch (\Doctrine\DBAL\Exception $error) {
                throw new ApiProblem(503, 'view_unavailable', sprintf('The view %s could not be read.', $view), previous: $error);
            }

            $response = new StreamedResponse(function () use ($rows, $request, $query, $view, $timezone): void {
                $returned = 0;
                try {
                    echo '{"data":[';
                    // The generator is already at its first row (or finished).
                    while ($rows->valid()) {
                        echo ($returned === 0 ? '' : ','), json_encode($rows->current(), self::JSON);
                        if (++$returned % 500 === 0) {
                            flush();
                        }
                        $rows->next();
                    }
                    $more = $rows->getReturn();
                    echo '],"meta":', json_encode([
                        'view' => $view,
                        'approved' => ViewCatalog::isApproved($view),
                        'limit' => $query->limit,
                        'offset' => $query->offset,
                        'returned' => $returned,
                        'has_more' => $more,
                        'group' => $query->group,
                        'reporting_timezone' => $timezone,
                    ], self::JSON), ',"links":', json_encode([
                        'self' => $request->getUri(),
                        'schema' => $this->url($request, '/api/v1/views/'.$view.'/schema'),
                        'next' => $more ? $this->pageUrl($request, $query->offset + $query->limit, $query->limit) : null,
                    ], self::JSON), '}';
                } catch (\Throwable $error) {
                    // The status line is already sent; stop, so the body is visibly incomplete.
                    $this->logger->error('A reporting API response was interrupted.', ['view' => $view, 'rows_sent' => $returned, 'exception_class' => $error::class]);
                }
            });
            $this->protect($response);
            $response->headers->set('Content-Type', 'application/json');

            return $response;
        });
    }

    #[Route('/api/v1/openapi.json', name: 'api_v1_openapi', methods: ['GET'])]
    public function openApi(Request $request): Response
    {
        return $this->answer(function () use ($request): Response {
            $token = $this->guard->authenticate($request);
            try {
                $document = $this->openApi->build($token->readableViews($this->settings->privateViewsAllowed()), rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/'));
            } catch (\InvalidArgumentException|\RuntimeException $error) {
                throw new ApiProblem(503, 'configuration_invalid', 'The reporting API settings on this installation are invalid. An administrator needs to correct them.', previous: $error);
            }

            return $this->json($document);
        });
    }

    /** Authenticates the request and checks that its token may read the view. */
    private function readable(Request $request, string $view): ApiToken
    {
        $token = $this->guard->authenticate($request);
        $this->guard->authorize($token, $view);

        return $token;
    }

    /** @param callable(): Response $handler */
    private function answer(callable $handler): Response
    {
        if (!$this->features->isEnabled('reporting_api')) {
            throw new NotFoundHttpException();
        }
        try {
            return $handler();
        } catch (ApiProblem $problem) {
            if ($problem->status >= 500) {
                $this->logger->error('A reporting API request failed.', ['code' => $problem->problemCode, 'exception_class' => $problem->getPrevious() !== null ? $problem->getPrevious()::class : null]);
            }

            return $this->protect($problem->toResponse($this->documentation->url('reporting.api-errors')));
        } catch (\Throwable $error) {
            $this->logger->error('A reporting API request failed.', ['exception_class' => $error::class]);

            return $this->protect((new ApiProblem(500, 'internal_error', 'The request could not be completed.'))->toResponse($this->documentation->url('reporting.api-errors')));
        }
    }

    private function reportingTimezone(): string
    {
        try {
            return $this->schema->reportingTimezone();
        } catch (\RuntimeException $error) {
            throw new ApiProblem(503, 'configuration_invalid', 'The reporting timezone on this installation is invalid. An administrator needs to correct it.', previous: $error);
        }
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): JsonResponse
    {
        $response = new JsonResponse(json_encode($data, self::JSON), Response::HTTP_OK, [], true);
        $this->protect($response);

        return $response;
    }

    private function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Authorization');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function url(Request $request, string $path): string
    {
        return $request->getSchemeAndHttpHost().$request->getBaseUrl().$path;
    }

    private function pageUrl(Request $request, int $offset, int $limit): string
    {
        $query = $request->query->all();
        $query['limit'] = (string) $limit;
        $query['offset'] = (string) $offset;

        return $this->url($request, $request->getPathInfo()).'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
