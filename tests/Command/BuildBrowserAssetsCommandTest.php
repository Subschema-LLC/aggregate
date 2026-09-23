<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BuildBrowserAssetsCommand;
use App\Service\BrowserAssetBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BuildBrowserAssetsCommandTest extends TestCase
{
    public function testExplicitBuildRunsSharedBuilder(): void
    {
        $builder = $this->createMock(BrowserAssetBuilder::class);
        $builder->expects(self::once())->method('build');
        $command = new CommandTester(new BuildBrowserAssetsCommand($builder));

        self::assertSame(Command::SUCCESS, $command->execute([]));
        self::assertStringContainsString('Browser scripts were minified', $command->getDisplay());
    }

    public function testFailureDoesNotReportSuccess(): void
    {
        $builder = $this->createMock(BrowserAssetBuilder::class);
        $builder->expects(self::once())->method('build')->willThrowException(new \RuntimeException('Another browser asset build is running.'));
        $command = new CommandTester(new BuildBrowserAssetsCommand($builder));

        self::assertSame(Command::FAILURE, $command->execute([]));
        self::assertStringContainsString('Another browser asset build is running', $command->getDisplay());
        self::assertStringNotContainsString('were minified', $command->getDisplay());
    }
}
