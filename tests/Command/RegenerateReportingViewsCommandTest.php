<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RegenerateReportingViewsCommand;
use App\Service\CustomDataSettings;
use App\Service\Glossary\GlossarySync;
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
        $regenerated = false;
        $views->expects(self::once())->method('regenerate')->willReturnCallback(static function () use (&$regenerated): array {
            $regenerated = true;

            return ReportingViewManager::VIEW_NAMES;
        });
        $glossary = $this->createMock(GlossarySync::class);
        $glossary->expects(self::once())->method('sync')->willReturnCallback(static function () use (&$regenerated): array {
            self::assertTrue($regenerated, 'Glossary sync must follow successful regeneration.');

            return [];
        });
        $tester = $this->tester($views, glossary: $glossary);

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

    #[DataProvider('failureModes')]
    public function testFailureDoesNotReportRegenerationSuccess(\Throwable $error, int $exitCode, bool $decorated): void
    {
        $views = $this->createMock(ReportingViewManager::class);
        $views->method('regenerate')->willThrowException($error);
        $tester = $this->tester($views);

        self::assertSame($exitCode, $tester->execute([], ['decorated' => $decorated]));
        self::assertStringContainsString($error->getMessage(), $tester->getDisplay());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
    }

    public static function failureModes(): iterable
    {
        foreach ([false, true] as $decorated) {
            yield [new \RuntimeException('Missing privileges for <info>view</info>.'), Command::FAILURE, $decorated];
            yield [new \InvalidArgumentException('Invalid <error>view</error>.'), Command::INVALID, $decorated];
        }
    }

    public function testSyncFailureReportsThatViewsAlreadyChangedAndExitsUnsuccessfully(): void
    {
        $views = $this->createMock(ReportingViewManager::class);
        $views->expects(self::once())->method('regenerate')->willReturn(ReportingViewManager::VIEW_NAMES);
        $glossary = $this->createMock(GlossarySync::class);
        $glossary->expects(self::once())->method('sync')->willThrowException(new \RuntimeException('Glossary table is missing.'));
        $tester = $this->tester($views, glossary: $glossary);
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Reporting views were regenerated', $tester->getDisplay());
        self::assertStringContainsString('app:analytics:glossary:sync', $tester->getDisplay());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
    }

    private function tester(ReportingViewManager $views, ?CustomDataSettings $settings = null, ?GlossarySync $glossary = null): CommandTester
    {
        if ($glossary === null) {
            $glossary = $this->createMock(GlossarySync::class);
            $glossary->expects(self::never())->method('sync');
        }

        return new CommandTester(new RegenerateReportingViewsCommand($views, $settings ?? $this->createStub(CustomDataSettings::class), $glossary));
    }
}
