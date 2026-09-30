<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SetupCodeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-setup-code-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testEnsureCreatesAPrivateFileOnceAndKeepsTheSameCode(): void
    {
        $code = new SetupCode($this->directory);
        self::assertFalse($code->isPending());
        self::assertNull($code->current());

        $value = $code->ensure();

        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/D', $value);
        self::assertTrue($code->isPending());
        self::assertSame($value, $code->current());
        self::assertSame($value, $code->ensure());
        self::assertSame(0600, fileperms($this->directory.'/'.SetupCode::FILE) & 0777);
        self::assertStringContainsString($value, (string) file_get_contents($this->directory.'/'.SetupCode::FILE));
    }

    public function testMatchingIsForgivingAboutFormattingButNotAboutSymbols(): void
    {
        $code = new SetupCode($this->directory);
        $value = $code->ensure();
        $compact = str_replace('-', '', $value);

        self::assertTrue($code->matches($value));
        self::assertTrue($code->matches(strtolower($value)));
        self::assertTrue($code->matches(' '.implode(' ', str_split(strtolower($compact), 4)).' '));
        self::assertTrue($code->matches($compact));
        self::assertFalse($code->matches(substr($value, 0, -1)));
        self::assertFalse($code->matches(''));
        self::assertFalse($code->matches(null));
        self::assertFalse($code->matches([$value]));
        self::assertFalse((new SetupCode($this->directory.'/elsewhere'))->matches($value));
    }

    public function testRemovedOrDamagedFilesStopMatchingAndAreReplacedWithANewCode(): void
    {
        $code = new SetupCode($this->directory);
        $first = $code->ensure();
        $code->remove();
        self::assertFalse($code->isPending());
        self::assertFalse($code->matches($first));

        file_put_contents($code->path(), 'edited by hand');
        self::assertNull($code->current());
        self::assertFalse($code->matches('edited by hand'));
        $replacement = $code->ensure();
        self::assertSame($replacement, $code->current());
    }

    public function testNormalizeOnlyReformatsTwelveSymbols(): void
    {
        self::assertSame('ABCD-EFGH-JKLM', SetupCode::normalize('abcd efgh jklm'));
        self::assertSame('ABCDE', SetupCode::normalize('ab-cde'));
    }
}
