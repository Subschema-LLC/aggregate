<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\VerifyReleasePackageCommand;
use App\Service\ReleasePackageVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class VerifyReleasePackageCommandTest extends TestCase
{
    #[DataProvider('releaseBranches')]
    public function testVerifiesProvidedLocalFilesAndReportsSuccessWithoutClaimingAnInstall(string $branch, bool $decorated): void
    {
        $verifier = $this->createMock(ReleasePackageVerifier::class);
        $verifier->expects(self::once())->method('verify')
            ->with('/tmp/release files/package.zip', '/tmp/release files/manifest.json', '/tmp/release files/manifest.sig')
            ->willReturn(['version' => '1.2.3', 'branch' => $branch, 'package' => ['sha256' => str_repeat('a', 64)]]);
        $tester = new CommandTester(new VerifyReleasePackageCommand($verifier));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'package' => '/tmp/release files/package.zip',
            'manifest' => '/tmp/release files/manifest.json',
            'signature' => '/tmp/release files/manifest.sig',
        ], ['decorated' => $decorated]));
        self::assertStringContainsString('Verified Aggregate 1.2.3 from '.$branch, $tester->getDisplay());
        self::assertStringContainsString(str_repeat('a', 64), $tester->getDisplay());
        self::assertStringContainsString('installation has not been changed', $tester->getDisplay());
        self::assertStringContainsString('migrations', $tester->getDisplay());
    }

    public static function releaseBranches(): iterable
    {
        foreach ([false, true] as $decorated) {
            yield ['master', $decorated];
            yield ['fix/<info>test</>', $decorated];
        }
    }

    #[DataProvider('failureMessages')]
    public function testVerificationFailureReturnsFailureAndEscapesConsoleMarkup(string $message, bool $decorated): void
    {
        $verifier = $this->createMock(ReleasePackageVerifier::class);
        $verifier->expects(self::once())->method('verify')
            ->willThrowException(new \RuntimeException($message));
        $tester = new CommandTester(new VerifyReleasePackageCommand($verifier));

        self::assertSame(Command::FAILURE, $tester->execute([
            'package' => '/tmp/package.zip',
            'manifest' => '/tmp/manifest.json',
            'signature' => '/tmp/manifest.sig',
        ], ['decorated' => $decorated]));
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertStringNotContainsString("\033]8;", $tester->getDisplay());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
        self::assertStringNotContainsString('Verification is complete', $tester->getDisplay());
    }

    public static function failureMessages(): iterable
    {
        foreach ([false, true] as $decorated) {
            foreach ([
                'Untrusted release <error>metadata</error>.',
                'Invalid <fg=black;bg=green>metadata</>.',
                'Invalid <href=https://example.test>x</>.',
                'Invalid \\<error>metadata\\</error>.',
                'Invalid C:\\release\\',
            ] as $message) {
                yield [$message, $decorated];
            }
        }
    }
}
