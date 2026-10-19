<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ApiTokenCreateCommand;
use App\Command\ApiTokenListCommand;
use App\Command\ApiTokenRevokeCommand;
use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\ReportingApi\ApiTokenStore;
use App\Service\ReportingApi\ReportingApiSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ApiTokenCommandsTest extends TestCase
{
    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        foreach (['REPORTING_API_PRIVATE_VIEWS', 'REPORTING_API_MAX_PAGE_SIZE'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->projectDir = sys_get_temp_dir().'/aggregate-api-token-commands-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        $this->configure();
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->projectDir);
    }

    public function testATokenIsCreatedListedAndRevokedFromTheCommandLine(): void
    {
        $create = new CommandTester(new ApiTokenCreateCommand($this->store(), $this->settings()));
        self::assertSame(Command::SUCCESS, $create->execute(['name' => 'Notebook', '--view' => ['bi_anonymous_daily_v1'], '--expires' => '90d']));
        $display = $create->getDisplay();
        self::assertMatchesRegularExpression('/agg_([a-f0-9]{8})_[A-Za-z0-9_-]{43}/', $display);
        preg_match('/agg_([a-f0-9]{8})_[A-Za-z0-9_-]{43}/', $display, $matches);
        self::assertSame(1, substr_count($display, $matches[0]), 'The token is printed once, not in the example request');
        self::assertStringContainsString('$AGGREGATE_API_TOKEN', $display);
        self::assertStringNotContainsString('never expires', $display);
        self::assertSame($matches[1], $this->store()->authenticate($matches[0])?->id);

        $list = new CommandTester(new ApiTokenListCommand($this->store(), $this->settings(), new FeatureFlags($this->config())));
        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertStringContainsString('Notebook', $list->getDisplay());
        self::assertStringContainsString('bi_anonymous_daily_v1', $list->getDisplay());
        self::assertStringNotContainsString($matches[0], $list->getDisplay());
        self::assertStringNotContainsString('sha256:', $list->getDisplay());

        $revoke = new CommandTester(new ApiTokenRevokeCommand($this->store()));
        self::assertSame(Command::SUCCESS, $revoke->execute(['id' => $matches[1]], ['interactive' => false]));
        self::assertNull($this->store()->authenticate($matches[0]));
        self::assertSame(Command::INVALID, $revoke->execute(['id' => $matches[1]], ['interactive' => false]));
    }

    public function testNeverExpiringTokensAreAllowedWithAWarning(): void
    {
        $create = new CommandTester(new ApiTokenCreateCommand($this->store(), $this->settings()));
        self::assertSame(Command::SUCCESS, $create->execute(['name' => 'Forever', '--expires' => 'never']));
        self::assertStringContainsString('never expires', preg_replace('/\s+/', ' ', $create->getDisplay()));

        self::assertSame(Command::SUCCESS, $create->execute(['name' => 'Long', '--expires' => '50y', '--json' => true]));
        $json = json_decode($create->getDisplay(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('Long', $json['name']);
        self::assertStringStartsWith('agg_'.$json['id'].'_', $json['token']);
        self::assertNull($json['views']);
    }

    public function testInvalidRequestsAndTheFlagAreReported(): void
    {
        $create = new CommandTester(new ApiTokenCreateCommand($this->store(), $this->settings()));
        self::assertSame(Command::INVALID, $create->execute(['name' => 'Raw', '--private-view' => ['analytics_custom_events_v1']]));
        self::assertStringContainsString('reporting_api_private_views', $create->getDisplay());
        self::assertSame(Command::INVALID, $create->execute(['name' => 'Bad', '--view' => ['events']]));
        self::assertSame(Command::INVALID, $create->execute(['name' => 'Past', '--expires' => '2020-01-01']));

        $this->configure(flag: false);
        self::assertSame(Command::FAILURE, (new CommandTester(new ApiTokenCreateCommand($this->store(), $this->settings())))->execute(['name' => 'Off']));
        self::assertSame([], $this->store()->tokens());
    }

    private function config(): AggregateConfigLoader
    {
        return new AggregateConfigLoader($this->projectDir, 'test');
    }

    private function settings(): ReportingApiSettings
    {
        return new ReportingApiSettings($this->config());
    }

    private function store(): ApiTokenStore
    {
        return new ApiTokenStore($this->projectDir, new FeatureFlags($this->config()), $this->settings());
    }

    private function configure(bool $flag = true): void
    {
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([
            'feature_flags' => ['reporting_api' => ['enabled' => $flag, 'hide_from_navigation' => false]],
        ], 4));
    }
}
