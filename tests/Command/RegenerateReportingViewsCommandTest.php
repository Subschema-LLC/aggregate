<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RegenerateReportingViewsCommand;
use App\Service\CustomDataSettings;
use App\Service\ReportingViewManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RegenerateReportingViewsCommandTest extends TestCase
{
    public function testDefaultModeRegeneratesViews(): void
    {
        $views = $this->createMock(ReportingViewManager::class);
        $views->expects(self::once())->method('regenerate')->willReturn(ReportingViewManager::VIEW_NAMES);
        $tester = $this->tester($views);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('analytics_custom_events_v1', $tester->getDisplay());
        self::assertStringContainsString('unsuppressed', $tester->getDisplay());
    }

    public function testDryRunWritesRawSqlWithoutRegenerating(): void
    {
        $views = $this->createMock(ReportingViewManager::class);
        $views->expects(self::never())->method('regenerate');
        $views->expects(self::once())->method('previewSql')->willReturn(['test' => 'CREATE VIEW test AS SELECT 1;']);
        $tester = $this->tester($views);

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertSame("CREATE VIEW test AS SELECT 1;\n\n", $tester->getDisplay());
    }

    public function testDiscoveryDoesNotRegenerateOrPrintEventValues(): void
    {
        $views = $this->createMock(ReportingViewManager::class);
        $views->expects(self::never())->method('regenerate');
        $views->expects(self::once())->method('discoverProperties')->with(12)->willReturn([
            ['key' => 'campaign', 'types' => ['string'], 'event_count' => 8],
        ]);
        $tester = $this->tester($views);

        self::assertSame(Command::SUCCESS, $tester->execute(['--discover' => true, '--sample-size' => '12']));
        self::assertStringContainsString('campaign', $tester->getDisplay());
        self::assertStringContainsString('string', $tester->getDisplay());
        self::assertMatchesRegularExpression('/No captured\s+!\s+values are displayed/', $tester->getDisplay());
    }

    public function testExportOutputsOnlyShareableYaml(): void
    {
        $yaml = "custom_data_properties:\n  campaign:\n    consent_required: true\n    column: campaign\n";
        $views = $this->createMock(ReportingViewManager::class);
        $views->expects(self::never())->method('regenerate');
        $settings = $this->createMock(CustomDataSettings::class);
        $settings->expects(self::once())->method('exportYaml')->willReturn($yaml);
        $tester = $this->tester($views, $settings);

        self::assertSame(Command::SUCCESS, $tester->execute(['--export-model' => true]));
        self::assertSame($yaml, $tester->getDisplay());
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsNeverRegenerate(array $options): void
    {
        $views = $this->createMock(ReportingViewManager::class);
        $views->expects(self::never())->method('regenerate');
        $views->expects(self::never())->method('discoverProperties');
        $tester = $this->tester($views);

        self::assertSame(Command::INVALID, $tester->execute($options));
    }

    public static function invalidOptions(): iterable
    {
        yield [['--dry-run' => true, '--discover' => true]];
        yield [['--export-model' => true, '--dry-run' => true]];
        yield 'sample size alone must not trigger DDL' => [['--sample-size' => '25']];
        yield 'explicit default sample size still requires discovery' => [['--sample-size' => '1000']];
        foreach (['0', '10001', '-1', 'invalid', '1.5'] as $size) {
            yield [['--discover' => true, '--sample-size' => $size]];
        }
    }

    public function testFailureDoesNotReportRegenerationSuccess(): void
    {
        $views = $this->createMock(ReportingViewManager::class);
        $views->method('regenerate')->willThrowException(new \RuntimeException('View privileges are missing.'));
        $tester = $this->tester($views);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('View privileges are missing.', $tester->getDisplay());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
    }

    private function tester(ReportingViewManager $views, ?CustomDataSettings $settings = null): CommandTester
    {
        return new CommandTester(new RegenerateReportingViewsCommand($views, $settings ?? $this->createStub(CustomDataSettings::class)));
    }
}
