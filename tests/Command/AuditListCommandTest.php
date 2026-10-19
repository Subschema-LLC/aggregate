<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AuditListCommand;
use App\Service\AggregateConfigLoader;
use App\Service\Operations\AuditTrail;
use App\Service\Operations\AuditTrailLog;
use App\Service\Operations\AuditTrailSettings;
use App\Tests\Service\Operations\OperationsDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

final class AuditListCommandTest extends TestCase
{
    private string $projectDir;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-audit-list-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config', 0700, true);
        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([AuditTrailSettings::KEY => ['sign_ins' => true, 'operations' => false]]));
        $this->connection = OperationsDatabase::connection();
        $trail = new AuditTrail($this->connection);
        $today = new \DateTimeImmutable('today 09:00', new \DateTimeZone('UTC'));
        $trail->record('settings', 'branding_saved', 'succeeded', $today->modify('-10 days'), null, 'scott', null, 'Changed aggregate.yaml: brand_name');
        $trail->record('users', 'user_created', 'succeeded', $today->modify('-1 day'), 'ana', 'scott');
        $trail->record('sign_ins', 'sign_in_failed', 'failed', $today, null, null, null, 'No account has the username entered.');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->projectDir.'/config/*') ?: []);
        rmdir($this->projectDir.'/config');
        rmdir($this->projectDir);
    }

    public function testItListsTheNewestEntriesWithThePageFilters(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertLessThan(strpos($display, 'Branding saved'), strpos($display, 'User created'));
        self::assertStringContainsString('Sign in failed', $display);

        self::assertSame(Command::SUCCESS, $tester->execute(['--days' => '2', '--area' => ['users', 'sign_ins'], '--outcome' => ['succeeded']]));
        self::assertStringContainsString('User created', $tester->getDisplay());
        self::assertStringNotContainsString('Sign in failed', $tester->getDisplay());
        self::assertStringNotContainsString('Branding saved', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--user' => '-']));
        self::assertStringContainsString('Sign in failed', $tester->getDisplay());
        self::assertStringNotContainsString('User created', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--search' => 'brand name', '--limit' => '1']));
        self::assertStringContainsString('Branding saved', $tester->getDisplay());
        self::assertStringContainsString('Showing the first 1', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--area' => ['updates']]));
        self::assertStringContainsString('No entries match these filters.', $tester->getDisplay());
    }

    public function testJsonListsEveryFieldInTheChosenOrder(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true, '--oldest-first' => true]));
        $entries = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['branding_saved', 'user_created', 'sign_in_failed'], array_column($entries, 'action'));
        self::assertSame(['occurred_at', 'id', 'area', 'action', 'outcome', 'subject', 'user', 'task', 'details'], array_keys($entries[0]));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T09:00:00\+00:00$/', $entries[0]['occurred_at']);

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true, '--area' => ['updates']]));
        self::assertSame([], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testInvalidOptionsAreRefused(): void
    {
        $tester = $this->tester();
        foreach ([
            [['--from' => 'yesterday'], 'The start date must be a date'],
            [['--area' => ['everything']], 'An unknown area'],
            [['--days' => '0'], '--days must be a whole number'],
            [['--days' => '3', '--from' => '2026-10-01'], 'Use --days or --from, not both.'],
            [['--limit' => '200000'], '--limit must be a whole number from 1 to 100000.'],
            [['--task' => 'x'], 'The job run number must be a whole number.'],
        ] as [$options, $message]) {
            self::assertSame(Command::INVALID, $tester->execute($options), json_encode($options));
            self::assertStringContainsString($message, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        }
    }

    public function testTheSummaryShowsEachAreaSwitchAndItsEntries(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--summary' => true]));
        $lines = preg_split('/\R/', $tester->getDisplay()) ?: [];
        $row = static fn (string $area): string => (string) current(array_filter($lines, static fn (string $line): bool => str_starts_with(trim($line), $area.' ')));

        self::assertMatchesRegularExpression('/sign_ins\s+Sign-ins\s+yes\s+1\s/', $row('sign_ins'));
        self::assertMatchesRegularExpression('/operations\s+Manual runs\s+no\s+0\s/', $row('operations'));
        self::assertMatchesRegularExpression('/settings\s+Settings changes\s+yes\s+1\s/', $row('settings'));

        file_put_contents($this->projectDir.'/config/aggregate.yaml', Yaml::dump([AuditTrailSettings::KEY => ['settings' => 'sometimes']]));
        self::assertSame(Command::FAILURE, $this->tester()->execute(['--summary' => true]));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new AuditListCommand(
            new AuditTrailLog($this->connection),
            new AuditTrailSettings(new AggregateConfigLoader($this->projectDir, 'test')),
        ));
    }
}
