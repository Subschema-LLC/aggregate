<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\VerifyReleasePackageCommand;
use App\Service\ReleasePackageVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class VerifyReleasePackageCommandTest extends TestCase
{
    public function testVerifiesProvidedLocalFilesAndReportsSuccessWithoutClaimingAnInstall(): void
    {
        $verifier = $this->createMock(ReleasePackageVerifier::class);
        $verifier->expects(self::once())->method('verify')
            ->with('/tmp/release files/package.zip', '/tmp/release files/manifest.json', '/tmp/release files/manifest.sig')
            ->willReturn(['version' => '1.2.3', 'branch' => 'master', 'package' => ['sha256' => str_repeat('a', 64)]]);
        $tester = new CommandTester(new VerifyReleasePackageCommand($verifier));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'package' => '/tmp/release files/package.zip',
            'manifest' => '/tmp/release files/manifest.json',
            'signature' => '/tmp/release files/manifest.sig',
        ]));
        self::assertStringContainsString('Verified Aggregate 1.2.3 from master', $tester->getDisplay());
        self::assertStringContainsString(str_repeat('a', 64), $tester->getDisplay());
        self::assertStringContainsString('installation has not been changed', $tester->getDisplay());
        self::assertStringContainsString('migrations', $tester->getDisplay());
    }

    public function testVerificationFailureReturnsFailureAndEscapesConsoleMarkup(): void
    {
        $verifier = $this->createMock(ReleasePackageVerifier::class);
        $verifier->expects(self::once())->method('verify')
            ->willThrowException(new \RuntimeException('Untrusted release <error>metadata</error>.'));
        $tester = new CommandTester(new VerifyReleasePackageCommand($verifier));

        self::assertSame(Command::FAILURE, $tester->execute([
            'package' => '/tmp/package.zip',
            'manifest' => '/tmp/manifest.json',
            'signature' => '/tmp/manifest.sig',
        ]));
        self::assertStringContainsString('Untrusted release <error>metadata</error>.', $tester->getDisplay());
        self::assertStringNotContainsString('[OK]', $tester->getDisplay());
        self::assertStringNotContainsString('Verification is complete', $tester->getDisplay());
    }
}
