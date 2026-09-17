<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ExportEventExamplesCommand;
use App\Service\AggregateConfigLoader;
use App\Service\CustomDataSettings;
use App\Service\EventExampleGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ExportEventExamplesCommandTest extends TestCase
{
    #[DataProvider('modes')]
    public function testStdoutContainsOnlyJsonAndWorksWithTheDashboardDisabled(array $options, array $expectedModes): void
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('isDashboardEnabled')->willReturn(false);
        $config->method('all')->willReturn([]);
        $config->method('getWithEnvFallback')->willReturnArgument(1);
        $config->expects(self::never())->method('set');
        $config->expects(self::never())->method('setMany');
        $tester = $this->tester(new CustomDataSettings($config));

        self::assertSame(Command::SUCCESS, $tester->execute($options, ['capture_stderr_separately' => true, 'decorated' => true]));
        $bundle = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expectedModes, array_keys($bundle['examples']));
        self::assertTrue($bundle['synthetic']);
        self::assertSame('', $tester->getErrorOutput());
    }

    public static function modes(): iterable
    {
        yield [[], ['anonymous', 'enhanced']];
        yield [['--mode' => 'all'], ['anonymous', 'enhanced']];
        yield [['--mode' => 'anonymous'], ['anonymous']];
        yield [['--mode' => 'enhanced'], ['enhanced']];
        yield [['--mode' => 'enhanced', '--example' => 'ecommerce'], ['enhanced']];
    }

    #[DataProvider('invalidModes')]
    public function testInvalidModeWritesOnlyAGenericErrorToStderr(string $mode): void
    {
        $settings = $this->createMock(CustomDataSettings::class);
        $settings->expects(self::never())->method('toArray');
        $tester = $this->tester($settings);

        self::assertSame(Command::INVALID, $tester->execute(['--mode' => $mode], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertSame("Choose --mode=all, --mode=anonymous, or --mode=enhanced.\n", $tester->getErrorOutput());
    }

    public static function invalidModes(): iterable
    {
        yield [''];
        yield ['Anonymous'];
        yield ['<error>private-value</error>'];
        yield ['all,enhanced'];
    }

    public function testModelFailureDoesNotWritePartialJsonOrLeakExceptionDetails(): void
    {
        $settings = $this->createMock(CustomDataSettings::class);
        $settings->method('toArray')->willThrowException(new \RuntimeException('Private configuration: <error>secret-value</error>'));
        $settings->expects(self::never())->method('save');
        $tester = $this->tester($settings);

        self::assertSame(Command::FAILURE, $tester->execute([], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Check the saved data model', $tester->getErrorOutput());
        self::assertStringNotContainsString('secret-value', $tester->getErrorOutput());
        self::assertStringNotContainsString('Private configuration', $tester->getErrorOutput());
    }

    public function testInvalidExampleSourceIsRejectedWithoutEchoingInputOrReadingConfiguration(): void
    {
        $settings = $this->createMock(CustomDataSettings::class);
        $settings->expects(self::never())->method('toArray');
        $tester = $this->tester($settings);

        self::assertSame(Command::INVALID, $tester->execute(['--example' => '<error>private-value</error>'], ['capture_stderr_separately' => true]));
        self::assertSame('', $tester->getDisplay());
        self::assertSame("Choose --example=model or --example=ecommerce.\n", $tester->getErrorOutput());
    }

    private function tester(CustomDataSettings $settings): CommandTester
    {
        return new CommandTester(new ExportEventExamplesCommand(new EventExampleGenerator($settings)));
    }
}
