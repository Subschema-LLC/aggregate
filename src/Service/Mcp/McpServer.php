<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\AppBranding;
use App\Service\InstalledRelease;
use App\Service\ReportingApi\ApiProblem;
use App\Service\ReportingApi\ReportingApiSettings;
use App\Service\ViewSync\ViewCatalog;

/**
 * The Model Context Protocol methods the server answers, one JSON-RPC 2.0
 * request at a time (docs/MCP.md#protocol). It is stateless: there is no
 * session, every request carries its credential, and nothing is pushed to
 * the client, so it runs on ordinary PHP hosting.
 *
 * - initialize, ping
 * - tools/list, tools/call (McpTools)
 * - resources/list, resources/read: the reading guide and one description
 *   per readable view, so a client can attach them as context
 *
 * Notifications and responses from the client need no answer. Every tool
 * call is recorded by McpUsageLog.
 */
class McpServer
{
    /** Protocol revisions this server speaks, newest first. */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];
    /** The revision a request without an MCP-Protocol-Version header is taken to use. */
    public const DEFAULT_PROTOCOL_VERSION = '2025-03-26';
    public const GUIDE_URI = 'aggregate://guide';
    public const VIEW_URI_PREFIX = 'aggregate://views/';

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;
    public const RESOURCE_NOT_FOUND = -32002;

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly McpTools $tools,
        private readonly McpUsageLog $usage,
        private readonly ReportingApiSettings $settings,
        private readonly AppBranding $branding,
        private readonly InstalledRelease $release,
    ) {
    }

    /**
     * Answers one JSON-RPC message, or returns null when it needs no answer
     * (a notification or a response).
     *
     * @param mixed $message the decoded JSON body
     *
     * @return array<string, mixed>|null
     */
    public function handle(mixed $message, McpPrincipal $principal): ?array
    {
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return self::error(null, self::INVALID_REQUEST, 'Send one JSON-RPC 2.0 message as a JSON object. Batches are not supported.');
        }
        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        if ($hasId && !is_string($id) && !is_int($id)) {
            return self::error(null, self::INVALID_REQUEST, 'The id must be a string or a number.');
        }
        if (!isset($message['method'])) {
            // A response to a request this server never sends.
            return array_key_exists('result', $message) || array_key_exists('error', $message) ? null : self::error($id, self::INVALID_REQUEST, 'A request needs a method.');
        }
        if (!is_string($message['method'])) {
            return self::error($id, self::INVALID_REQUEST, 'The method must be a string.');
        }
        if (!$hasId) {
            return null;
        }
        $params = $message['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return self::error($id, self::INVALID_PARAMS, 'params must be an object.');
        }

        try {
            $result = match ($message['method']) {
                'initialize' => $this->initialize($params),
                'ping' => new \ArrayObject(),
                'tools/list' => ['tools' => $this->tools->definitions()],
                'tools/call' => $this->callTool($params, $principal),
                'resources/list' => ['resources' => $this->resources($principal)],
                'resources/templates/list' => ['resourceTemplates' => []],
                'resources/read' => $this->readResource($params, $principal),
                default => throw new McpProtocolError(self::METHOD_NOT_FOUND, sprintf('This server does not support the method %s.', mb_substr($message['method'], 0, 64))),
            };
        } catch (McpProtocolError $error) {
            return self::error($id, $error->rpcCode, $error->getMessage());
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        $version = is_string($requested) && in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];
        $name = $this->branding->getName();

        return [
            'protocolVersion' => $version,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'aggregate',
                'title' => $name,
                'version' => $this->version(),
            ],
            'instructions' => McpViewGuide::instructions($name),
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool(array $params, McpPrincipal $principal): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name)) {
            throw new McpProtocolError(self::INVALID_PARAMS, 'tools/call needs the tool name in params.name.');
        }
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw new McpProtocolError(self::INVALID_PARAMS, 'params.arguments must be an object.');
        }
        if (!in_array($name, McpTools::NAMES, true)) {
            throw new McpProtocolError(self::INVALID_PARAMS, sprintf('There is no tool named %s. Use %s.', mb_substr($name, 0, 64), implode(', ', McpTools::NAMES)));
        }
        $started = hrtime(true);
        $outcome = $this->tools->call($name, $arguments, $principal);
        $milliseconds = (int) round((hrtime(true) - $started) / 1e6);
        $this->usage->record($principal, $name, $outcome['view'], $outcome['error'] === null, $outcome['rows'], $milliseconds, $outcome['group_by'], $outcome['error']);

        $text = $outcome['error'] === null
            ? json_encode($outcome['data'], self::JSON)
            : sprintf('Error (%s): %s', $outcome['error'], $outcome['message']);

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => $outcome['data'],
            'isError' => $outcome['error'] !== null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function resources(McpPrincipal $principal): array
    {
        $resources = [[
            'uri' => self::GUIDE_URI,
            'name' => 'guide',
            'title' => 'How to read the reporting views',
            'description' => 'The rules every answer from these views follows.',
            'mimeType' => 'text/markdown',
        ]];
        foreach ($principal->grant->readableViews($this->settings->privateViewsAllowed()) as $view) {
            if (ViewCatalog::isMetadata($view)) {
                continue;
            }
            $resources[] = [
                'uri' => self::VIEW_URI_PREFIX.$view,
                'name' => $view,
                'title' => ViewCatalog::describe($view),
                'description' => 'Columns, labels, codes and notes for '.$view.', as describe_view returns them.',
                'mimeType' => 'application/json',
            ];
        }

        return $resources;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function readResource(array $params, McpPrincipal $principal): array
    {
        $uri = $params['uri'] ?? null;
        if (!is_string($uri)) {
            throw new McpProtocolError(self::INVALID_PARAMS, 'resources/read needs params.uri.');
        }
        if ($uri === self::GUIDE_URI) {
            return ['contents' => [['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => McpViewGuide::instructions($this->branding->getName())]]];
        }
        $view = str_starts_with($uri, self::VIEW_URI_PREFIX) ? substr($uri, strlen(self::VIEW_URI_PREFIX)) : '';
        if ($view === '' || !ViewCatalog::isKnown($view) || !$principal->grant->mayRead($view, $this->settings->privateViewsAllowed())) {
            throw new McpProtocolError(self::RESOURCE_NOT_FOUND, sprintf('There is no resource %s you may read. resources/list lists them.', mb_substr($uri, 0, 160)));
        }
        try {
            $description = $this->tools->description($view);
        } catch (ApiProblem $problem) {
            throw new McpProtocolError(self::INTERNAL_ERROR, $problem->getMessage());
        }

        return ['contents' => [['uri' => $uri, 'mimeType' => 'application/json', 'text' => json_encode($description, self::JSON)]]];
    }

    private function version(): string
    {
        try {
            $release = $this->release->read();
        } catch (\Throwable) {
            $release = null;
        }
        $version = is_array($release) && is_string($release['version'] ?? null) ? $release['version'] : null;

        return $version ?? 'development';
    }

    /** @return array<string, mixed> */
    public static function error(string|int|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
