<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\QueueRetentionCommand;
use App\Service\AggregateConfigLoader;
use App\Service\AnalyticsDataLifecyclePolicy;
use App\Service\Operations\QueueRetention;
use App\Service\ParsedYamlCache;
use App\Tests\Service\Operations\QueueRetentionTest;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/** queue_retention_days from the command line, with the Data lifecycle page's rules. */
final class QueueRetentionCommandTest extends TestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['QUEUE_RETENTION_DAYS'], $_SERVER['QUEUE_RETENTION_DAYS'], $_ENV['SERVER_ROLE'], $_SERVER['SERVER_ROLE']);
        $this->directory = sys_get_temp_dir().'/aggregate-queue-retention-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory.'/config');
        file_put_contents($this->directory.'/config/aggregate.yaml', "app_host: https://admin.example.com\n");
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        (new Filesystem())->remove($this->directory);
    }

    public function testTheQueueAndItsRetentionAreShownAndTheRetentionIsSet(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tester = $this->tester(new QueueRetention($connection));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('deleted after 7 days', $tester->getDisplay());
        self::assertStringContainsString('Nothing is queued.', $tester->getDisplay());

        QueueRetentionTest::queue($connection);
        QueueRetentionTest::message($connection, 'failed', '2026-11-01 08:00:00');
        self::assertSame(0, $tester->execute(['--json' => true]));
        self::assertSame(['queue_retention_days' => 7, 'queues' => ['failed' => ['messages' => 1, 'oldest' => '2026-11-01T08:00:00+00:00']]],
            json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR));

        foreach (['0', '366', 'week'] as $refused) {
            self::assertSame(1, $this->tester(new QueueRetention($connection))->execute(['days' => $refused]), $refused);
        }
        self::assertArrayNotHasKey('queue_retention_days', Yaml::parseFile($this->directory.'/config/aggregate.yaml'));

        self::assertSame(0, $this->tester(new QueueRetention($connection))->execute(['days' => '14']));
        self::assertSame(14, Yaml::parseFile($this->directory.'/config/aggregate.yaml')['queue_retention_days']);
        $shown = $this->tester(new QueueRetention($connection));
        self::assertSame(0, $shown->execute([]));
        self::assertStringContainsString('deleted after 14 days', $shown->getDisplay());
        self::assertStringContainsString('failed (every attempt failed)', $shown->getDisplay());
    }

    private function tester(QueueRetention $queue): CommandTester
    {
        $config = new AggregateConfigLoader($this->directory, 'prod', parsedYaml: new ParsedYamlCache($this->directory.'/cache'));

        return new CommandTester(new QueueRetentionCommand($config, new AnalyticsDataLifecyclePolicy($config), $queue));
    }
}
