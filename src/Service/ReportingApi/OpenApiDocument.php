<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

use App\Service\AppBranding;

/**
 * The OpenAPI 3.1 document for one token, built at request time from the
 * same catalog and columns the API serves, so it cannot drift from what the
 * API does. It lists only the views the token may read: a private view's
 * column names are not published to every token holder. A view that does not
 * exist in this database (geography off, custom views not generated) is left
 * out. It names no software version.
 */
class OpenApiDocument
{
    public const API_VERSION = '1';

    private const TIMESTAMP_PATTERN = '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]+)?$';

    public function __construct(
        private readonly ViewSchema $schema,
        private readonly ReportingApiSettings $settings,
        private readonly AppBranding $branding,
    ) {
    }

    /**
     * @param list<string> $views     the views the token may read
     * @param string       $serverUrl the installation's address, without a trailing slash
     *
     * @return array<string, mixed>
     */
    public function build(array $views, string $serverUrl): array
    {
        $maxLimit = $this->settings->maxPageSize();
        $defaultLimit = $this->settings->defaultPageSize();
        $paths = [
            '/api/v1/views' => ['get' => [
                'operationId' => 'listViews',
                'summary' => 'The views this token may read',
                'responses' => [
                    '200' => ['description' => 'Views with descriptions and links', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ViewList']]]],
                    '401' => ['$ref' => '#/components/responses/Problem'],
                    '429' => ['$ref' => '#/components/responses/Problem'],
                ],
            ]],
        ];
        $schemas = $this->commonSchemas();
        $available = [];
        foreach ($views as $view) {
            try {
                $columns = $this->schema->columns($view);
            } catch (ApiProblem) {
                continue;
            }
            $available[] = $view;
            $rowSchema = $view.'_row';
            $properties = [];
            foreach ($columns as $column) {
                $properties[$column['name']] = $this->columnSchema($column);
            }
            $schemas[$rowSchema] = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
            $schemas[$view.'_page'] = [
                'type' => 'object',
                'required' => ['data', 'meta', 'links'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$rowSchema]],
                    'meta' => ['$ref' => '#/components/schemas/PageMeta'],
                    'links' => ['$ref' => '#/components/schemas/PageLinks'],
                ],
            ];
            $paths['/api/v1/views/'.$view] = ['get' => [
                'operationId' => 'rows_'.$view,
                'summary' => ViewSchema::describe($view),
                'description' => 'Rows of '.$view.'. Filter with filter[column]=value or filter[column][operator]=value; each column lists the operators it accepts. Filters are combined with AND.',
                'parameters' => [...$this->pageParameters($columns, $defaultLimit, $maxLimit), ...$this->filterParameters($columns)],
                'responses' => [
                    '200' => ['description' => 'A page of rows', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$view.'_page']]]],
                    '400' => ['$ref' => '#/components/responses/Problem'],
                    '401' => ['$ref' => '#/components/responses/Problem'],
                    '403' => ['$ref' => '#/components/responses/Problem'],
                    '429' => ['$ref' => '#/components/responses/Problem'],
                    '503' => ['$ref' => '#/components/responses/Problem'],
                ],
            ]];
        }
        if ($available !== []) {
            $paths['/api/v1/views/{view}/schema'] = ['get' => [
                'operationId' => 'viewSchema',
                'summary' => 'Columns of a view: names, types, descriptions, time zones and filter operators',
                'parameters' => [['name' => 'view', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => $available]]],
                'responses' => [
                    '200' => ['description' => 'The view\'s columns', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ViewSchema']]]],
                    '401' => ['$ref' => '#/components/responses/Problem'],
                    '403' => ['$ref' => '#/components/responses/Problem'],
                    '404' => ['$ref' => '#/components/responses/Problem'],
                ],
            ]];
        }
        $paths['/api/v1/openapi.json'] = ['get' => [
            'operationId' => 'openApi',
            'summary' => 'This document, for the token that requests it',
            'responses' => ['200' => ['description' => 'An OpenAPI 3.1 document', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]]],
        ]];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => $this->branding->getName().' reporting API',
                'version' => self::API_VERSION,
                'description' => 'Read-only access to the reporting views. Approved views hold suppressed counts and labels; private views, when a token may read them, hold row-level or unsuppressed data. Time columns are labelled in the time zone each column states, without an offset. Errors are RFC 9457 problem details with a stable code.',
            ],
            'servers' => [['url' => $serverUrl]],
            'security' => [['bearerToken' => []]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerToken' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An API token created in the dashboard (Reporting → API tokens) or with app:api:token:create.']],
                'responses' => ['Problem' => ['description' => 'An error', 'content' => [ApiProblem::CONTENT_TYPE => ['schema' => ['$ref' => '#/components/schemas/Problem']]]]],
                'schemas' => $schemas,
            ],
        ];
    }

    /**
     * @param array{catalog_type: string, description?: ?string, timezone: ?string} $column
     *
     * @return array<string, mixed>
     */
    private function columnSchema(array $column): array
    {
        $schema = match ($column['catalog_type']) {
            'INT64' => ['type' => ['integer', 'null']],
            'FLOAT64' => ['type' => ['number', 'null']],
            'BOOL' => ['type' => ['boolean', 'null']],
            'DATE' => ['type' => ['string', 'null'], 'format' => 'date'],
            'TIMESTAMP' => ['type' => ['string', 'null'], 'pattern' => self::TIMESTAMP_PATTERN],
            default => ['type' => ['string', 'null']],
        };
        $description = (string) ($column['description'] ?? '');
        if ($column['timezone'] !== null) {
            $description = trim($description.' Labelled in '.$column['timezone'].', without an offset.');
        }
        if ($description !== '') {
            $schema['description'] = $description;
        }

        return $schema;
    }

    /**
     * @param list<array{name: string}> $columns
     *
     * @return list<array<string, mixed>>
     */
    private function pageParameters(array $columns, int $defaultLimit, int $maxLimit): array
    {
        $names = implode(', ', array_column($columns, 'name'));

        return [
            ['name' => 'fields', 'in' => 'query', 'description' => 'Columns to return, comma-separated, in this order. Default: all ('.$names.').', 'schema' => ['type' => 'string']],
            ['name' => 'sort', 'in' => 'query', 'description' => 'Columns to sort by, comma-separated; prefix with - for descending. Rows are always ordered completely, so pages are stable.', 'schema' => ['type' => 'string']],
            ['name' => 'limit', 'in' => 'query', 'description' => 'Rows per page.', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $maxLimit, 'default' => $defaultLimit]],
            ['name' => 'offset', 'in' => 'query', 'description' => 'Rows to skip. Follow links.next for the next page.', 'schema' => ['type' => 'integer', 'minimum' => 0, 'default' => 0]],
        ];
    }

    /**
     * One equality parameter per column, describing its other operators.
     *
     * @param list<array{name: string, catalog_type: string, operators: list<string>, timezone: ?string}> $columns
     *
     * @return list<array<string, mixed>>
     */
    private function filterParameters(array $columns): array
    {
        $parameters = [];
        foreach ($columns as $column) {
            $others = array_values(array_diff($column['operators'], ['eq']));
            $schema = $this->columnSchema(['catalog_type' => $column['catalog_type'], 'timezone' => $column['timezone']]);
            $schema['type'] = array_values(array_diff((array) $schema['type'], ['null']))[0];
            unset($schema['description']);
            $parameters[] = [
                'name' => 'filter['.$column['name'].']',
                'in' => 'query',
                'description' => sprintf(
                    'Rows where %1$s equals this value. Other operators: %2$s, written filter[%1$s][operator]=value%3$s.',
                    $column['name'],
                    implode(', ', $others),
                    in_array('in', $others, true) ? ' (in takes a comma-separated list)' : '',
                ),
                'schema' => $schema,
            ];
        }

        return $parameters;
    }

    /** @return array<string, mixed> */
    private function commonSchemas(): array
    {
        $link = ['type' => ['string', 'null'], 'format' => 'uri'];

        return [
            'Problem' => [
                'type' => 'object',
                'required' => ['type', 'title', 'status', 'code', 'detail'],
                'properties' => [
                    'type' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'code' => ['type' => 'string', 'enum' => array_keys(ApiProblem::TITLES)],
                    'detail' => ['type' => 'string'],
                ],
            ],
            'PageMeta' => [
                'type' => 'object',
                'properties' => [
                    'view' => ['type' => 'string'],
                    'approved' => ['type' => 'boolean'],
                    'limit' => ['type' => 'integer'],
                    'offset' => ['type' => 'integer'],
                    'returned' => ['type' => 'integer'],
                    'has_more' => ['type' => 'boolean'],
                    'reporting_timezone' => ['type' => 'string'],
                ],
            ],
            'PageLinks' => [
                'type' => 'object',
                'properties' => ['self' => $link, 'schema' => $link, 'next' => $link],
            ],
            'ViewList' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'approved' => ['type' => 'boolean'],
                            'links' => ['type' => 'object', 'properties' => ['rows' => $link, 'schema' => $link]],
                        ],
                    ]],
                    'meta' => ['type' => 'object', 'properties' => ['private_views_allowed' => ['type' => 'boolean'], 'reporting_timezone' => ['type' => 'string']]],
                ],
            ],
            'ViewSchema' => [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'approved' => ['type' => 'boolean'],
                            'unique_order' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'columns' => ['type' => 'array', 'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'type' => ['type' => 'string', 'enum' => array_values(ViewSchema::API_TYPES)],
                                    'description' => ['type' => ['string', 'null']],
                                    'timezone' => ['type' => ['string', 'null']],
                                    'filter_operators' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
