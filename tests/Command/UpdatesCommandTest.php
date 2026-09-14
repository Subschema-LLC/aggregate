<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\CheckUpdatesCommand;
use App\Command\PullUpdatesCommand;
use App\Service\ApplicationUpdateService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UpdatesCommandTest extends TestCase
{
    public function testCheckUsesCacheAndDoesNotPull(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::once())->method('check')->with(false)->willReturn($this->updateStatus());
        $updates->expects(self::never())->method('pull');
        $tester = new CommandTester(new CheckUpdatesCommand($updates));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Subschema-LLC/aggregate', $tester->getDisplay());
        self::assertStringContainsString('app:updates:pull', $tester->getDisplay());
        self::assertStringContainsString('DEPLOYMENT.md#updates', $tester->getDisplay());
    }

    #[DataProvider('checkStates')]
    public function testRefreshedJsonReportsStatusAndExitCode(string $state, int $exitCode): void
    {
        $status = $this->updateStatus($state);
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::once())->method('check')->with(true)->willReturn($status);
        $updates->expects(self::never())->method('pull');
        $tester = new CommandTester(new CheckUpdatesCommand($updates));

        self::assertSame($exitCode, $tester->execute(['--refresh' => true, '--json' => true]));
        self::assertSame($status, json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function checkStates(): iterable
    {
        foreach (['available', 'up_to_date', 'ahead', 'diverged'] as $state) {
            yield $state => [$state, Command::SUCCESS];
        }
        foreach (['unknown', 'error', 'unavailable'] as $state) {
            yield $state => [$state, Command::FAILURE];
        }
    }

    public function testFailedCheckDoesNotSuggestAPull(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('check')->willReturn($this->updateStatus('error'));
        $tester = new CommandTester(new CheckUpdatesCommand($updates));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('[ERROR]', $tester->getDisplay());
        self::assertStringNotContainsString('app:updates:pull', $tester->getDisplay());
    }

    public function testSuccessfulPullReportsThatDeploymentStillNeedsCompletion(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->expects(self::once())->method('pull')->willReturn([
            'branch' => 'development',
            'previous_commit' => str_repeat('a', 40),
            'current_commit' => str_repeat('b', 40),
            'changed' => true,
        ]);
        $tester = new CommandTester(new PullUpdatesCommand($updates));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('source code was updated', $tester->getDisplay());
        self::assertStringContainsString('Deployment is not complete', $tester->getDisplay());
        self::assertStringContainsString('migrations', $tester->getDisplay());
        self::assertStringContainsString(str_repeat('b', 40), $tester->getDisplay());
    }

    public function testNoOpPullDoesNotClaimCodeWasUpdated(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('pull')->willReturn([
            'branch' => 'development',
            'previous_commit' => str_repeat('a', 40),
            'current_commit' => str_repeat('a', 40),
            'changed' => false,
        ]);
        $tester = new CommandTester(new PullUpdatesCommand($updates));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/source code is\s+unchanged/', $tester->getDisplay());
        self::assertStringNotContainsString('source code was updated', $tester->getDisplay());
    }

    public function testRejectedPullReturnsFailureAndDoesNotClaimSuccess(): void
    {
        $updates = $this->createMock(ApplicationUpdateService::class);
        $updates->method('pull')->willThrowException(new \RuntimeException('The checkout contains local changes.'));
        $tester = new CommandTester(new PullUpdatesCommand($updates));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('local changes', $tester->getDisplay());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
    }

    private function updateStatus(string $state = 'available'): array
    {
        return [
            'state' => $state,
            'branch' => 'development',
            'current_commit' => str_repeat('a', 40),
            'latest_commit' => str_repeat('b', 40),
            'checked_at' => 1789426800,
            'message' => 'Update status: '.$state,
            'compare_url' => 'https://github.com/Subschema-LLC/aggregate/compare/'.str_repeat('a', 40).'...'.str_repeat('b', 40),
        ];
    }
}
