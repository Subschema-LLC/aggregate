<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WebsiteScopeCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Under PHP-FPM every request builds a new catalog. The health result must
 * carry over between them while the files are unchanged, so a monitor's polls
 * do not read every website's settings file each time.
 */
final class WebsiteScopeCatalogHealthTest extends TestCase
{
    private string $projectDir;
    private string $overlay;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-scope-health-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/config/website-scopes/websites', 0700, true);
        $this->overlay = $this->projectDir.'/config/website-scopes/websites/'.str_repeat('ab', 12).'.yaml';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testAResultCarriesOverToTheNextRequestUntilAFileChanges(): void
    {
        // Two documents of the same size: one with a setting that cannot vary
        // by website, one valid.
        $invalid = "admin_token: 'x'\n";
        $valid = "collection_profile: strict\n";
        $invalid = str_pad(rtrim($invalid), strlen($valid) - 1, ' ')."\n";
        self::assertSame(strlen($valid), strlen($invalid));
        $settled = time() - 60;

        $this->write($invalid, $settled);
        self::assertTrue($this->request()->hasInvalidOverlay());

        // The same name, size and time: a later request reuses the stored
        // result instead of reading the file again.
        $this->write($valid, $settled);
        self::assertTrue($this->request()->hasInvalidOverlay());

        // Any change to the file's time is noticed, and so is a file that
        // changed moments ago, whose result is not stored yet.
        $this->write($valid, $settled + 1);
        self::assertFalse($this->request()->hasInvalidOverlay());
        $this->write($invalid, time());
        self::assertTrue($this->request()->hasInvalidOverlay());
        $this->write($valid, time());
        self::assertFalse($this->request()->hasInvalidOverlay(), 'a recently changed file is read on every request');
    }

    private function request(): WebsiteScopeCatalog
    {
        return new WebsiteScopeCatalog($this->projectDir, 'test');
    }

    private function write(string $contents, int $modified): void
    {
        file_put_contents($this->overlay, $contents);
        touch($this->overlay, $modified);
        clearstatcache(true, $this->overlay);
    }
}
