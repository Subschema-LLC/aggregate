<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\Mcp\OAuth\OAuthStore;
use App\Service\ReportingApi\ApiTokenStore;
use App\Service\ReportingApi\ReportingApiSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * The MCP server and the assistant sign-in endpoints over HTTP, as an
 * assistant sees them: the feature flag, credentials and their challenge,
 * the JSON-RPC methods, the usage log, and the whole sign-in from
 * registration to revocation. The administrator's approval page is covered
 * by McpAuthorizeControllerTest; here the approval is recorded directly.
 */
final class McpControllerTest extends WebTestCase
{
    private const REDIRECT = 'https://assistant.example/callback';
    private const VERIFIER = 'a-verifier-that-is-long-enough-to-be-valid-0123456789';

    private string $projectDir;
    private array $environment;
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['REPORTING_API_PRIVATE_VIEWS', 'REPORTING_API_MAX_PAGE_SIZE', 'REPORTING_TIMEZONE'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-mcp-http-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->client = self::createClient();
        $this->client->disableReboot();
        // The sign-in limit counts per address and outlives a test run; give each test its own.
        $this->client->setServerParameter('REMOTE_ADDR', '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testNothingExistsWhileTheFlagIsOff(): void
    {
        $secret = $this->boot(flag: false);
        foreach (['/mcp', '/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-authorization-server'] as $path) {
            $this->client->request($path === '/mcp' ? 'POST' : 'GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $this->client->request('POST', '/oauth/token', ['grant_type' => 'refresh_token']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testACredentialIsNeededAndTheChallengePointsToSignIn(): void
    {
        $this->boot();
        $this->client->request('POST', '/mcp', content: $this->message('ping'));
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"');

        $script = $this->token(mcp: false);
        $this->rpc('ping', [], $script, 401);
        self::assertStringContainsString('error="invalid_token"', (string) $this->client->getResponse()->headers->get('WWW-Authenticate'), 'A token without the MCP grant is refused like an unknown one');

        $this->client->request('POST', '/mcp?access_token=x', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$script], content: $this->message('ping'));
        self::assertResponseStatusCodeSame(400);
    }

    public function testTheProtocolMethodsAndToolsAnswerAndCallsAreRecorded(): void
    {
        $secret = $this->boot(views: ['bi_anonymous_daily_v1']);

        $init = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \ArrayObject(), 'clientInfo' => ['name' => 'test', 'version' => '1']], $secret);
        self::assertSame('2025-06-18', $init['result']['protocolVersion']);
        self::assertSame('aggregate', $init['result']['serverInfo']['name']);
        self::assertSame(['tools', 'resources'], array_keys($init['result']['capabilities']));
        self::assertStringContainsString('never zero', $init['result']['instructions']);
        self::assertSame('2025-11-25', $this->rpc('initialize', ['protocolVersion' => '1999-01-01'], $secret)['result']['protocolVersion'], 'An unknown revision gets the newest');

        $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        self::assertResponseStatusCodeSame(202);
        self::assertSame('', (string) $this->client->getResponse()->getContent());

        self::assertSame(['list_views', 'describe_view', 'query_view', 'get_coverage'], array_column($this->rpc('tools/list', [], $secret)['result']['tools'], 'name'));

        $call = $this->rpc('tools/call', ['name' => 'query_view', 'arguments' => ['view' => 'bi_anonymous_daily_v1', 'group_by' => ['website_token'], 'order_by' => ['-event_count']]], $secret)['result'];
        self::assertFalse($call['isError']);
        self::assertSame([['website_token' => 'site-b', 'event_count' => 30], ['website_token' => 'site-a', 'event_count' => 21]], $call['structuredContent']['rows']);
        self::assertSame($call['structuredContent'], json_decode($call['content'][0]['text'], true), 'The text content is the same data');

        $refused = $this->rpc('tools/call', ['name' => 'query_view', 'arguments' => ['view' => 'bi_anonymous_events_v1']], $secret)['result'];
        self::assertTrue($refused['isError']);
        self::assertStringContainsString('view_not_permitted', $refused['content'][0]['text']);

        $entries = $this->connection->fetchAllAssociative('SELECT category, operation, subject, outcome, actor, details FROM audit_trail ORDER BY id');
        self::assertSame([
            ['category' => 'assistant', 'operation' => 'mcp_query_view', 'subject' => 'bi_anonymous_daily_v1', 'outcome' => 'succeeded', 'actor' => 'token 00000000 (Assistant)', 'details' => null],
            ['category' => 'assistant', 'operation' => 'mcp_query_view', 'subject' => 'bi_anonymous_events_v1', 'outcome' => 'failed', 'actor' => 'token 00000000 (Assistant)', 'details' => null],
        ], array_map(static fn (array $entry): array => [...$entry, 'details' => null], $entries));
        self::assertMatchesRegularExpression('/^rows=2; ms=\d+; group_by=website_token$/', (string) $entries[0]['details']);
        self::assertStringContainsString('error=view_not_permitted', (string) $entries[1]['details']);
    }

    public function testResourcesListTheGuideAndReadableViews(): void
    {
        $secret = $this->boot(views: ['bi_anonymous_daily_v1']);

        $resources = array_column($this->rpc('resources/list', [], $secret)['result']['resources'], 'uri');
        self::assertSame(['aggregate://guide', 'aggregate://views/bi_anonymous_daily_v1'], $resources, 'Label views are left out of the list');

        $guide = $this->rpc('resources/read', ['uri' => 'aggregate://guide'], $secret)['result']['contents'][0];
        self::assertSame('text/markdown', $guide['mimeType']);
        $view = json_decode($this->rpc('resources/read', ['uri' => 'aggregate://views/bi_anonymous_daily_v1'], $secret)['result']['contents'][0]['text'], true);
        self::assertSame('event_day', $view['time_column']);
        self::assertSame(-32002, $this->rpc('resources/read', ['uri' => 'aggregate://views/bi_anonymous_events_v1'], $secret)['error']['code']);
    }

    public function testMalformedRequestsGetJsonRpcAndHttpErrors(): void
    {
        $secret = $this->boot();
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json'];

        $this->client->request('GET', '/mcp', server: $auth);
        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('Allow', 'POST');
        $this->client->request('DELETE', '/mcp', server: $auth);
        self::assertResponseStatusCodeSame(405);

        $this->client->request('POST', '/mcp', server: $auth, content: '{not json');
        self::assertResponseStatusCodeSame(400);
        self::assertSame(-32700, $this->decode()['error']['code']);
        $this->client->request('POST', '/mcp', server: $auth, content: '[{"jsonrpc":"2.0","id":1,"method":"ping"}]');
        self::assertSame(-32600, $this->decode()['error']['code'], 'Batches are not supported');
        $this->client->request('POST', '/mcp', server: [...$auth, 'HTTP_MCP_PROTOCOL_VERSION' => '2024-01-01'], content: $this->message('ping'));
        self::assertResponseStatusCodeSame(400);

        self::assertSame(-32601, $this->rpc('prompts/list', [], $secret)['error']['code']);
        self::assertSame(-32602, $this->rpc('tools/call', ['name' => 'run_sql', 'arguments' => ['sql' => 'SELECT 1']], $secret)['error']['code']);
        self::assertSame(-32602, $this->rpc('tools/call', ['name' => 'list_views', 'arguments' => ['a', 'b']], $secret)['error']['code']);
        self::assertSame([], (array) $this->rpc('ping', [], $secret)['result']);
    }

    public function testMetadataDescribesTheSignInEndpoints(): void
    {
        $this->boot();
        $resource = $this->get('/.well-known/oauth-protected-resource/mcp');
        self::assertSame('http://localhost/mcp', $resource['resource']);
        self::assertSame(['http://localhost'], $resource['authorization_servers']);
        self::assertSame($resource, $this->get('/.well-known/oauth-protected-resource'));

        $server = $this->get('/.well-known/oauth-authorization-server');
        self::assertSame('http://localhost', $server['issuer']);
        self::assertSame('http://localhost/dashboard/oauth/authorize', $server['authorization_endpoint']);
        self::assertSame('http://localhost/oauth/token', $server['token_endpoint']);
        self::assertSame('http://localhost/oauth/register', $server['registration_endpoint']);
        self::assertSame(['S256'], $server['code_challenge_methods_supported']);
        self::assertSame(['none'], $server['token_endpoint_auth_methods_supported']);
        self::assertTrue($server['client_id_metadata_document_supported']);
        self::assertSame($server, $this->get('/.well-known/openid-configuration'));
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    public function testAnAssistantSignsInUsesRefreshesAndRevokes(): void
    {
        $this->boot();

        $this->client->request('POST', '/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['client_name' => 'Example assistant', 'redirect_uris' => [self::REDIRECT], 'grant_types' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_method' => 'none']));
        self::assertResponseStatusCodeSame(201);
        $registered = $this->decode();
        self::assertSame('none', $registered['token_endpoint_auth_method']);

        $store = new OAuthStore($this->projectDir);
        $client = $store->client($registered['client_id']);
        self::assertNotNull($client);
        $code = $store->issueCode($client, self::REDIRECT, OAuthStore::challenge(self::VERIFIER), ['bi_anonymous_daily_v1'], [], null, 'scott');

        $this->client->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'client_id' => $registered['client_id'], 'code_verifier' => 'wrong-verifier-wrong-verifier-wrong-verifier-0000']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_grant', $this->decode()['error']);

        $code = $store->issueCode($client, self::REDIRECT, OAuthStore::challenge(self::VERIFIER), ['bi_anonymous_daily_v1'], [], null, 'scott');
        $this->client->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'client_id' => $registered['client_id'], 'code_verifier' => self::VERIFIER, 'resource' => 'http://localhost/mcp']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $tokens = $this->decode();
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertSame(3600, $tokens['expires_in']);

        $views = $this->rpc('tools/call', ['name' => 'list_views', 'arguments' => new \ArrayObject()], $tokens['access_token'])['result']['structuredContent']['views'];
        self::assertSame('bi_anonymous_daily_v1', $views[0]['name']);
        self::assertSame('assistant', $this->connection->fetchOne('SELECT category FROM audit_trail'));
        self::assertStringStartsWith('assistant ', (string) $this->connection->fetchOne('SELECT actor FROM audit_trail'));

        $this->client->request('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $registered['client_id']]);
        self::assertResponseIsSuccessful();
        $renewed = $this->decode();
        $this->rpc('ping', [], $tokens['access_token'], 401);
        $this->rpc('ping', [], $renewed['access_token']);

        $this->client->request('POST', '/oauth/revoke', ['token' => $renewed['refresh_token'], 'client_id' => $registered['client_id']]);
        self::assertResponseIsSuccessful();
        $this->rpc('ping', [], $renewed['access_token'], 401);
    }

    public function testTokenRequestsAreChecked(): void
    {
        $this->boot();
        foreach ([
            [['grant_type' => 'client_credentials', 'client_id' => 'x'], 'unsupported_grant_type'],
            [['grant_type' => 'authorization_code', 'client_id' => 'x'], 'invalid_request'],
            [['grant_type' => 'refresh_token'], 'invalid_request'],
            [['grant_type' => 'refresh_token', 'client_id' => 'x', 'refresh_token' => 'y', 'resource' => 'https://elsewhere.example/mcp'], 'invalid_target'],
            [['grant_type' => 'refresh_token', 'client_id' => 'x', 'refresh_token' => 'aggmcpr_00000000_'.str_repeat('A', 43)], 'invalid_grant'],
        ] as [$form, $error]) {
            $this->client->request('POST', '/oauth/token', $form);
            self::assertResponseStatusCodeSame(400);
            self::assertSame($error, $this->decode()['error']);
        }
        $this->client->request('POST', '/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['redirect_uris' => ['http://assistant.example/cb']]));
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_redirect_uri', $this->decode()['error']);
        $this->client->request('POST', '/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['redirect_uris' => [self::REDIRECT], 'grant_types' => ['client_credentials']]));
        self::assertSame('invalid_client_metadata', $this->decode()['error']);
    }

    public function testSignInRequestsAreLimitedPerAddress(): void
    {
        $this->boot();
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('POST', '/oauth/revoke', ['token' => 'x', 'client_id' => 'y']);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('POST', '/oauth/revoke', ['token' => 'x', 'client_id' => 'y']);
        self::assertResponseStatusCodeSame(429);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
    }

    /**
     * @param array<string, mixed>|\ArrayObject<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array|\ArrayObject $params, string $secret, int $status = 200): array
    {
        $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: $this->message($method, $params));
        self::assertResponseStatusCodeSame($status);

        return $status === 200 ? $this->decode() : [];
    }

    /** @param array<string, mixed>|\ArrayObject<string, mixed> $params */
    private function message(string $method, array|\ArrayObject $params = []): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params === [] ? new \ArrayObject() : $params], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * Replaces the configuration, credential files and database with test
     * copies, and returns the secret of a token for assistants.
     *
     * @param list<string>|null $views
     */
    private function boot(bool $flag = true, ?array $views = null, bool $mcp = true): string
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'feature_flags' => ['mcp_server' => ['enabled' => true, 'hide_from_navigation' => true]],
        ], 4));
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        @unlink($this->projectDir.'/'.ApiTokenStore::FILE);
        [, $secret] = (new ApiTokenStore($this->projectDir, new FeatureFlags($config), new ReportingApiSettings($config)))->create('Assistant', $views, [], null, 'test', $mcp);
        // The token file's ID is random; give it a known one so the audit actor is predictable.
        $file = Yaml::parseFile($this->projectDir.'/'.ApiTokenStore::FILE);
        $id = (string) array_key_first($file['tokens']);
        $secret = str_replace('agg_'.$id.'_', 'agg_00000000_', $secret);
        $file['tokens'] = ['00000000' => [...$file['tokens'][$id], 'hash' => 'sha256:'.hash('sha256', $secret)]];
        file_put_contents($this->projectDir.'/'.ApiTokenStore::FILE, Yaml::dump($file, 4));
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'feature_flags' => ['mcp_server' => ['enabled' => $flag, 'hide_from_navigation' => true]],
        ], 4));

        $container = self::getContainer();
        // A fresh loader: the one above has read the file before the flag was set.
        $container->set(AggregateConfigLoader::class, new AggregateConfigLoader($this->projectDir, 'test'));
        $container->set('doctrine.dbal.default_connection', $this->connection = $this->database());
        $container->set(ApiTokenStore::class, new ApiTokenStore($this->projectDir, new FeatureFlags($config), new ReportingApiSettings($config)));
        $container->set(OAuthStore::class, new OAuthStore($this->projectDir));

        return $secret;
    }

    /** A second token, created through the store the application uses. */
    private function token(bool $mcp): string
    {
        $config = new AggregateConfigLoader($this->projectDir, 'test');
        [, $secret] = (new ApiTokenStore($this->projectDir, new FeatureFlags($config), new ReportingApiSettings($config)))->create('Script', null, [], null, 'test', $mcp);

        return $secret;
    }

    private function database(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE daily (website_token TEXT, event_day TEXT, page_group TEXT, event_count INTEGER)');
        $connection->executeStatement('CREATE VIEW bi_anonymous_daily_v1 AS SELECT website_token, event_day, page_group, event_count FROM daily');
        $connection->executeStatement('CREATE TABLE audit_trail (id INTEGER PRIMARY KEY AUTOINCREMENT, occurred_at TEXT, category TEXT, operation TEXT, subject TEXT, outcome TEXT, actor TEXT, processing_task_id INTEGER, details TEXT)');
        foreach ([['site-a', '2026-10-01', '/blog', 12], ['site-a', '2026-10-02', '/blog', 9], ['site-b', '2026-10-01', '/pricing', 30]] as $row) {
            $connection->insert('daily', array_combine(['website_token', 'event_day', 'page_group', 'event_count'], $row));
        }

        return $connection;
    }
}
