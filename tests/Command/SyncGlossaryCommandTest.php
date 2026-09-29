<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SyncGlossaryCommand;
use App\Service\Glossary\GlossarySync;
use App\Service\Glossary\GlossaryValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SyncGlossaryCommandTest extends TestCase
{
    public function testSyncPrintsDimensionsLocalesAndFallbackSummary(): void
    {
        $sync = $this->createMock(GlossarySync::class);
        $sync->expects(self::once())->method('sync')->willReturn([
            'insert' => 10, 'change' => 0, 'delete' => 0, 'total' => 10, 'changed' => true,
            'written' => 10, 'dimensions' => 2, 'locales' => ['en', 'fr-CA'], 'fallback_counts' => ['en' => 0, 'fr-CA' => 4],
        ]);
        $tester = new CommandTester(new SyncGlossaryCommand($sync));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Synced 10 rows: 2 dimensions, 2 locales, 4 fallback labels (fr-CA: 4)', $tester->getDisplay());
    }

    #[DataProvider('readModes')]
    public function testReadModesNeverWrite(string $option, bool $changed, int $exitCode): void
    {
        $sync = $this->createMock(GlossarySync::class);
        $sync->expects(self::never())->method('sync');
        $sync->expects(self::once())->method('diff')->willReturn([
            'insert' => $changed ? 3 : 0, 'change' => $changed ? 2 : 0,
            'delete' => $changed ? 1 : 0, 'total' => 20, 'changed' => $changed,
        ]);
        $tester = new CommandTester(new SyncGlossaryCommand($sync));

        self::assertSame($exitCode, $tester->execute([$option => true]));
        self::assertStringContainsString('20 resolved rows', $tester->getDisplay());
        if ($option === '--check') {
            self::assertStringContainsString($changed ? 'Database differs from configuration' : 'In sync', $tester->getDisplay());
        }
    }

    public static function readModes(): iterable
    {
        yield 'dry run changed' => ['--dry-run', true, Command::SUCCESS];
        yield 'dry run current' => ['--dry-run', false, Command::SUCCESS];
        yield 'check changed' => ['--check', true, Command::FAILURE];
        yield 'check current' => ['--check', false, Command::SUCCESS];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsDoNotResolveOrWrite(array $options): void
    {
        $sync = $this->createMock(GlossarySync::class);
        $sync->expects(self::never())->method('sync');
        $sync->expects(self::never())->method('diff');
        $sync->expects(self::never())->method('rows');
        $tester = new CommandTester(new SyncGlossaryCommand($sync));

        self::assertSame(Command::INVALID, $tester->execute($options));
    }

    public static function invalidOptions(): iterable
    {
        yield [['--dry-run' => true, '--check' => true]];
        yield [['--dry-run' => true, '--export' => true]];
        yield [['--check' => true, '--export' => true]];
        yield [['--missing-only' => true]];
        yield [['--check' => true, '--missing-only' => true]];
    }

    public function testExportIsPlainCsvEscapesFormulasAndDoesNotAccessTheDatabase(): void
    {
        $rows = [];
        foreach (['=1+1', '+SUM(A1:A2)', '-1+2', '@SUM(A1)', '  =1+1', '<info>text</info>'] as $label) {
            $rows[] = $this->row($label);
        }
        $sync = $this->createMock(GlossarySync::class);
        $sync->expects(self::never())->method('sync');
        $sync->expects(self::never())->method('diff');
        $sync->expects(self::once())->method('rows')->willReturn($rows);
        $tester = new CommandTester(new SyncGlossaryCommand($sync));

        self::assertSame(Command::SUCCESS, $tester->execute(['--export' => true], ['decorated' => true]));
        $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim($tester->getDisplay())));
        self::assertCount(7, $lines);
        self::assertSame('entry_type', $lines[0][0]);
        foreach (array_slice($lines, 1, 5) as $line) {
            self::assertStringStartsWith("'", $line[4]);
        }
        self::assertSame('<info>text</info>', $lines[6][4]);
    }

    public function testMissingOnlyExportsOnlyFallbackLabelsIncludingCodeFallback(): void
    {
        $native = $this->row('Native');
        $fallback = $this->row('English fallback');
        $fallback['locale'] = 'fr-CA';
        $fallback['is_default_locale'] = 0;
        $code = $this->row('untranslated');
        $code['label_locale'] = null;
        $sync = $this->createMock(GlossarySync::class);
        $sync->expects(self::never())->method('sync');
        $sync->expects(self::never())->method('diff');
        $sync->method('rows')->willReturn([$native, $fallback, $code]);
        $tester = new CommandTester(new SyncGlossaryCommand($sync));

        self::assertSame(Command::SUCCESS, $tester->execute(['--export' => true, '--missing-only' => true]));
        self::assertStringNotContainsString('Native', $tester->getDisplay());
        self::assertStringContainsString('English fallback', $tester->getDisplay());
        self::assertStringContainsString('untranslated', $tester->getDisplay());
    }

    public function testValidationErrorsListAllFieldPathsAndExitTwo(): void
    {
        $sync = $this->createStub(GlossarySync::class);
        $sync->method('sync')->willThrowException(new GlossaryValidationException([
            'bi_glossary.values.device_class.tablett' => 'Unknown code.',
            'bi_glossary.locales' => 'Include the default locale.',
        ]));
        $tester = new CommandTester(new SyncGlossaryCommand($sync));

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('bi_glossary.values.device_class.tablett', $tester->getDisplay());
        self::assertStringContainsString('bi_glossary.locales', $tester->getDisplay());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
    }

    public function testDatabaseFailureExitsOneWithoutClaimingSuccess(): void
    {
        $sync = $this->createStub(GlossarySync::class);
        $sync->method('sync')->willThrowException(new \RuntimeException('Missing table: run migrations.'));
        $tester = new CommandTester(new SyncGlossaryCommand($sync));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('run migrations', $tester->getDisplay());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
    }

    private function row(string $label): array
    {
        return [
            'entry_type' => 'value', 'subject' => 'device_class', 'code' => 'tablet',
            'locale' => 'en', 'label' => $label, 'label_locale' => 'en', 'group_label' => null,
            'description' => null, 'description_locale' => null, 'sort_order' => 20,
            'is_default_locale' => 1, 'source' => 'builtin',
        ];
    }
}
