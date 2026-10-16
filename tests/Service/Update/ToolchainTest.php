<?php

declare(strict_types=1);

namespace App\Tests\Service\Update;

use App\Service\Update\Toolchain;
use PHPUnit\Framework\TestCase;

final class ToolchainTest extends TestCase
{
    private string $directory;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV['PHP_PATH'], $_SERVER['PHP_PATH']);
        $this->directory = sys_get_temp_dir().'/aggregate-toolchain-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }

    public function testTheCommandLineUsesItsOwnBinary(): void
    {
        self::assertSame([\PHP_BINARY], (new Toolchain($this->directory, 'cli', ['/not/used']))->php());
    }

    public function testTheWebsiteRunsEachCandidateAndTakesTheFirstThatMatchesItsPhp(): void
    {
        $missing = $this->fakePhp('missing', $this->version(), ['intl', 'zip']);
        $older = $this->fakePhp('older', '7.4', []);
        $matching = $this->fakePhp('matching', $this->version(), []);
        $toolchain = new Toolchain($this->directory, 'fpm-fcgi', [$this->directory.'/absent', $missing, $older, $matching]);

        self::assertSame([$matching], $toolchain->php());
        self::assertNull($toolchain->phpProblem());
    }

    public function testItExplainsWhatItFoundWhenNothingMatches(): void
    {
        $missing = $this->fakePhp('missing', $this->version(), ['intl', 'zip']);
        $toolchain = new Toolchain($this->directory, 'fpm-fcgi', [$this->directory.'/absent', $missing]);

        self::assertNull($toolchain->php());
        self::assertStringContainsString($missing.' (PHP '.$this->version().', without intl, zip)', (string) $toolchain->phpProblem());
        self::assertStringContainsString('Set PHP_PATH in .env.local', (string) $toolchain->phpProblem());

        // An empty search says so, instead of offering an empty path.
        $nothing = new Toolchain($this->directory, 'fpm-fcgi', [$this->directory.'/absent']);
        self::assertNull($nothing->php());
        self::assertStringContainsString('could not be found', (string) $nothing->phpProblem());
    }

    public function testPhpPathFromEnvLocalIsUsedAndChecked(): void
    {
        $matching = $this->fakePhp('matching', $this->version(), []);
        $_SERVER['PHP_PATH'] = $matching;
        self::assertSame([$matching], (new Toolchain($this->directory, 'fpm-fcgi', ['/not/used']))->php());

        $_SERVER['PHP_PATH'] = $this->fakePhp('older', '7.4', []);
        $toolchain = new Toolchain($this->directory, 'fpm-fcgi', [$matching]);
        self::assertNull($toolchain->php(), 'PHP_PATH is not second-guessed.');
        self::assertStringContainsString('PHP_PATH names '.$_SERVER['PHP_PATH'].' (PHP 7.4)', (string) $toolchain->phpProblem());
    }

    public function testItSuggestsAnotherPhpWithTheMissingExtensions(): void
    {
        $missing = $this->fakePhp('missing', $this->version(), ['intl']);
        // Another supported minor version than the one running the tests.
        $other = $this->fakePhp('other', $this->version() === '8.2' ? '8.4' : '8.2', []);
        $tooOld = $this->fakePhp('too-old', '8.1', []);
        $matching = $this->fakePhp('matching', $this->version(), []);

        self::assertSame($matching, (new Toolchain($this->directory, 'cli', [$missing, $tooOld, $other, $matching]))->suggestPhp(['intl'], '8.2.0'));
        self::assertSame($other, (new Toolchain($this->directory, 'cli', [$missing, $tooOld, $other]))->suggestPhp(['intl'], '8.2.0'));
        self::assertNull((new Toolchain($this->directory, 'cli', [$missing, $tooOld]))->suggestPhp(['intl'], '8.2.0'));
    }

    private function version(): string
    {
        return \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION;
    }

    /** A stand-in PHP that answers the version probe the way a real one would. @param list<string> $missing */
    private function fakePhp(string $name, string $version, array $missing): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, "#!/bin/sh\necho '".json_encode([$version, $missing])."'\n");
        chmod($path, 0700);

        return $path;
    }
}
