<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ApiTokenCreateCommand;
use App\Command\ApiTokenListCommand;
use App\Command\McpConnectionListCommand;
use App\Command\McpConnectionRevokeCommand;
use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\Mcp\OAuth\OAuthStore;
use App\Service\ReportingApi\ApiTokenStore;
use App\Service\ReportingApi\ReportingApiSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/** Headless MCP administration: assistant tokens and sign-in connections. */
final class McpCommandsTest extends TestCase
{
    private const REDIRECT = 'https://assistant.example/callback';
    private const VERIFIER = 'a-verifier-that-is-long-enough-to-be-valid-0123456789';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-mcp-commands-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->configure(api: false, mcp: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testATokenForAssistantsNeedsTheServerOn(): void
    {
        $create = new CommandTester(new ApiTokenCreateCommand($this->tokens(), $this->settings()));
        self::assertSame(Command::SUCCESS, $create->execute(['name' => 'Gemini CLI', '--mcp' => true]), 'The MCP server alone is enough to create tokens');
        self::assertStringContainsString('/mcp', $create->getDisplay());
        self::assertTrue(array_values($this->tokens()->tokens())[0]->mcp);

        $list = new CommandTester(new ApiTokenListCommand($this->tokens(), $this->settings(), new FeatureFlags($this->config())));
        $list->execute([]);
        self::assertStringContainsString('Gemini CLI [MCP]', $list->getDisplay());
        self::assertStringContainsString('MCP server', $list->getDisplay());

        $this->configure(api: true, mcp: false);
        $create = new CommandTester(new ApiTokenCreateCommand($this->tokens(), $this->settings()));
        self::assertSame(Command::INVALID, $create->execute(['name' => 'Not yet', '--mcp' => true]));
        self::assertStringContainsString('mcp_server', $create->getDisplay());
        self::assertSame(Command::SUCCESS, $create->execute(['name' => 'Script']));
        self::assertFalse($this->tokens()->find((string) array_key_last($this->tokens()->tokens()))?->mcp);

        $this->configure(api: false, mcp: false);
        $create = new CommandTester(new ApiTokenCreateCommand($this->tokens(), $this->settings()));
        self::assertSame(Command::FAILURE, $create->execute(['name' => 'Neither']));
    }

    public function testConnectionsAreListedAndRevokedWithoutShowingTokens(): void
    {
        $store = new OAuthStore($this->projectDir);
        $client = $store->registerClient('Example assistant', [self::REDIRECT]);
        $code = $store->issueCode($client, self::REDIRECT, OAuthStore::challenge(self::VERIFIER), ['bi_anonymous_daily_v1'], [], null, 'scott');
        [$connection, $access] = $store->exchangeCode($code, $client->id, self::REDIRECT, self::VERIFIER);

        $list = new CommandTester(new McpConnectionListCommand($store, $this->settings(), new FeatureFlags($this->config())));
        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertStringContainsString('Example assistant', $list->getDisplay());
        self::assertStringContainsString('bi_anonymous_daily_v1', $list->getDisplay());
        self::assertStringContainsString('by scott', $list->getDisplay());
        self::assertStringNotContainsString(substr($access, -43), $list->getDisplay());
        self::assertStringNotContainsString('sha256', $list->getDisplay());
        $list->execute(['--json' => true]);
        self::assertSame($connection->id, json_decode($list->getDisplay(), true)[0]['id']);

        $revoke = new CommandTester(new McpConnectionRevokeCommand($store));
        self::assertSame(Command::SUCCESS, $revoke->execute(['id' => $connection->id], ['interactive' => false]));
        self::assertNull($store->authenticate($access));
        self::assertSame(Command::INVALID, $revoke->execute(['id' => $connection->id], ['interactive' => false]));
    }

    private function tokens(): ApiTokenStore
    {
        return new ApiTokenStore($this->projectDir, new FeatureFlags($this->config()), $this->settings());
    }

    private function settings(): ReportingApiSettings
    {
        return new ReportingApiSettings($this->config());
    }

    private function config(): AggregateConfigLoader
    {
        return new AggregateConfigLoader($this->projectDir, 'test');
    }

    private function configure(bool $api, bool $mcp): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump(['feature_flags' => [
            'reporting_api' => ['enabled' => $api, 'hide_from_navigation' => true],
            'mcp_server' => ['enabled' => $mcp, 'hide_from_navigation' => true],
        ]], 4));
    }
}
