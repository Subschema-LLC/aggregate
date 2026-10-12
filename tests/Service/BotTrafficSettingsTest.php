<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\BotTrafficSettings;
use App\Service\PrivacyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class BotTrafficSettingsTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-bot-settings-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        unset($_ENV['BOT_TRAFFIC'], $_SERVER['BOT_TRAFFIC']);
        (new Filesystem())->remove($this->projectDir);
    }

    public function testLabelIsTheDefaultAndKeepsIngestionEnabled(): void
    {
        $loader = $this->loader([]);
        $settings = new BotTrafficSettings($loader);

        self::assertSame('label', $settings->mode());
        self::assertSame([], $settings->userAgents());
        self::assertFalse($settings->isInvalid());
        self::assertFalse($loader->hasLoadError());
        self::assertSame('label', (new PrivacyPolicy($loader))->botTraffic());
    }

    public function testValuesAreNormalizedFromYaml(): void
    {
        $settings = new BotTrafficSettings($this->loader(['bot_traffic' => ' Drop ', 'bot_user_agents' => [' ExampleMonitor ', 'examplemonitor', 'load-test']]));

        self::assertSame('drop', $settings->mode());
        self::assertSame(['ExampleMonitor', 'load-test'], $settings->userAgents(), 'duplicates are removed ignoring case');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'unknown mode' => [['bot_traffic' => 'block']];
        yield 'boolean mode' => [['bot_traffic' => true]];
        yield 'phrases not a list' => [['bot_user_agents' => 'ExampleMonitor']];
        yield 'short phrase' => [['bot_user_agents' => ['ab']]];
        yield 'long phrase' => [['bot_user_agents' => [str_repeat('x', 101)]]];
        yield 'control character' => [['bot_user_agents' => ["Example\nMonitor"]]];
        yield 'too many' => [['bot_user_agents' => array_map(static fn (int $i): string => 'phrase-'.$i, range(1, 51))]];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidValuesStopIngestionAndResolveToDrop(array $config): void
    {
        $loader = $this->loader($config);
        $settings = new BotTrafficSettings($loader);

        self::assertTrue($settings->isInvalid());
        self::assertSame('drop', $settings->mode());
        self::assertTrue($loader->hasLoadError());
        self::assertFalse((new PrivacyPolicy($loader))->isAnonymousTrackingEnabled());
    }

    public function testTheEnvironmentVariableOverridesYaml(): void
    {
        $_ENV['BOT_TRAFFIC'] = 'drop';
        $_SERVER['BOT_TRAFFIC'] = 'drop';
        $settings = new BotTrafficSettings($this->loader(['bot_traffic' => 'label']));
        self::assertSame('drop', $settings->mode());
        self::assertTrue($settings->hasEnvironmentOverride());

        $_ENV['BOT_TRAFFIC'] = 'sometimes';
        $_SERVER['BOT_TRAFFIC'] = 'sometimes';
        $loader = $this->loader(['bot_traffic' => 'label']);
        self::assertTrue($loader->hasLoadError());
        self::assertSame('drop', (new BotTrafficSettings($loader))->mode());
    }

    public function testBotRequestsComeFromTheUserAgentOrTheTrackersAutomationReport(): void
    {
        $policy = new PrivacyPolicy($this->loader(['bot_user_agents' => ['ExampleMonitor']]));
        $browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

        self::assertFalse($policy->isBotRequest($browser, 'desktop'));
        self::assertTrue($policy->isBotRequest($browser, 'bot'), 'the tracker reports navigator.webdriver as bot');
        self::assertTrue($policy->isBotRequest('Mozilla/5.0 (compatible; Googlebot/2.1)', 'desktop'), 'a client cannot clear a User-Agent match');
        self::assertTrue($policy->isBotRequest($browser.' examplemonitor/1.0', 'desktop'));
        self::assertFalse($policy->isBotRequest('curl/8.5.0', null));
    }

    /** @param array<string, mixed> $config */
    private function loader(array $config): AggregateConfigLoader
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump($config, 4, 2));

        return new AggregateConfigLoader($this->projectDir, 'prod');
    }
}
