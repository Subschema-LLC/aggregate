<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\UpdateMethodCommand;
use App\Service\AggregateConfigLoader;
use App\Service\ApplicationUpdateService;
use App\Service\Update\ApplicationUpdater;
use App\Service\UpdateSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UpdateMethodCommandTest extends TestCase
{
    public function testShowsAnUnchosenMethodAndTheOneThatFitsWithoutSaving(): void
    {
        $tester = $this->tester(source: $this->source(null, 'repository'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('Not chosen', $display);
        self::assertStringContainsString('Git clone (fits repository updates)', $display);
        self::assertStringContainsString('Directly from the repository (advanced)', $display);
        self::assertStringContainsString('app:updates:method release', $display);
    }

    public function testShowingAMismatchFails(): void
    {
        $source = $this->source('repository', 'release');
        $source['mismatch'] = 'The update method is repository, but the application directory is not a Git clone.';
        $tester = $this->tester(source: $source);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('not a Git clone', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    #[DataProvider('choices')]
    public function testChoosingSavesTheSameYamlSettingAsTheDashboard(string $method, string $detected, string $expected, bool $warns): void
    {
        $tester = $this->tester(saved: ['updates_method' => $method], detected: $detected);

        self::assertSame(Command::SUCCESS, $tester->execute(['method' => $method]));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString($expected, $display);
        self::assertStringContainsString('updates_method', $display);
        self::assertSame($warns, str_contains($display, '[WARNING]'));
    }

    public static function choices(): iterable
    {
        yield 'release' => ['release', 'release', 'now updates with release ZIPs', false];
        yield 'repository on a clone' => ['repository', 'repository', 'directly from the repository (advanced)', false];
        yield 'repository without .git' => ['repository', 'release', 'not a Git clone yet', true];
        yield 'release on a clone' => ['release', 'repository', 'is a Git clone', true];
        yield 'deployed another way, any directory' => ['deployment', 'release', 'by deploying the code another way', false];
        yield 'deployed another way from a clone' => ['deployment', 'repository', 'app:updates:deployed', false];
    }

    public function testInvalidMethodIsRejectedWithoutSaving(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['method' => 'git']));
        self::assertStringContainsString('updates_method must be release', $tester->getDisplay());
    }

    public function testMethodCannotChangeDuringAnUpdate(): void
    {
        $tester = $this->tester(busy: 'The update method cannot change while an update is running or needs attention.');

        self::assertSame(Command::FAILURE, $tester->execute(['method' => 'release']));
        self::assertStringContainsString('cannot change while an update', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    /** @param array<string, mixed>|null $source @param array<string, string>|null $saved */
    private function tester(?array $source = null, ?array $saved = null, string $detected = 'release', ?string $busy = null): CommandTester
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn([]);
        if ($saved !== null) {
            $config->expects(self::once())->method('setMany')->with($saved);
        } else {
            $config->expects(self::never())->method('setMany');
        }
        $updates = $this->createStub(ApplicationUpdateService::class);
        $updates->method('source')->willReturn($source ?? $this->source(null, $detected));
        $updates->method('detectedMethod')->willReturn($detected);
        $updater = $this->createStub(ApplicationUpdater::class);
        $updater->method('methodChangeProblem')->willReturn($busy);

        return new CommandTester(new UpdateMethodCommand(new UpdateSettings($config), $updates, $updater));
    }

    /** @return array{source: string, reason: string, method: ?string, detected: string, mismatch: ?string} */
    private function source(?string $method, string $detected): array
    {
        $effective = $method ?? $detected;

        return [
            'source' => $effective === 'repository' ? 'git' : 'release',
            'reason' => 'Test.',
            'method' => $method,
            'detected' => $detected,
            'mismatch' => null,
        ];
    }
}
