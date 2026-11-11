<?php

declare(strict_types=1);

namespace App\Tests\Service\Update;

use App\Service\Update\PhpReloadRequest;
use App\Service\Update\SystemCheck;
use PHPUnit\Framework\TestCase;

final class PhpReloadRequestTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/aggregate-reload-'.bin2hex(random_bytes(6));
        mkdir($this->project.'/var', 0775, true);
        mkdir($this->project.'/config');
        file_put_contents($this->project.'/config/preload.php', '<?php');
    }

    protected function tearDown(): void
    {
        @unlink($this->project.'/'.PhpReloadRequest::FILE);
        @unlink($this->project.'/config/preload.php');
        @rmdir($this->project.'/config');
        @rmdir($this->project.'/var');
        @rmdir($this->project);
    }

    public function testAFinishedUpdateLeavesARequestAPanelCanWatch(): void
    {
        $request = new PhpReloadRequest($this->project);
        self::assertNull($request->requestedAt());

        $request->request('release', 1_800_000_000);

        self::assertFileExists($this->project.'/var/php-reload-requested');
        self::assertSame(['requested_at' => 1_800_000_000, 'reason' => 'release'], json_decode((string) file_get_contents($request->path()), true));
        self::assertSame(1_800_000_000, $request->requestedAt());
        $request->clear();
        self::assertNull($request->requestedAt());
    }

    public function testPhpThatRevalidatesFilesNeedsNoReload(): void
    {
        $request = new PhpReloadRequest($this->project);
        $request->request('release', 1_800_000_000);

        $rows = $this->rows($request->checks($this->runtime(['started_at' => 1_700_000_000])));

        self::assertSame(['opcache'], array_keys($rows));
        self::assertSame(SystemCheck::OK, $rows['opcache']['status']);
        self::assertNull($request->requestedAt(), 'The request is answered: this PHP picks up the new files itself.');
    }

    public function testPreloadingWaitsForPhpToStartAgain(): void
    {
        $request = new PhpReloadRequest($this->project);
        $request->request('git', 1_800_000_000);
        $preloading = ['preload' => $this->project.'/config/preload.php', 'started_at' => 1_799_999_000, 'restarted_at' => 1_800_000_500];

        // An OPcache reset does not load preloaded classes again.
        $rows = $this->rows($request->checks($this->runtime($preloading)));

        self::assertSame(SystemCheck::WARNING, $rows['opcache']['status']);
        self::assertStringContainsString('preloads Aggregate\'s classes', $rows['opcache']['detail']);
        self::assertSame(SystemCheck::WARNING, $rows['php_reload']['status']);
        self::assertStringContainsString('still runs the code from before it', $rows['php_reload']['detail']);
        self::assertSame(1_800_000_000, $request->requestedAt());

        // A panel watching the request can reload within the same second.
        $rows = $this->rows($request->checks($this->runtime(['started_at' => 1_800_000_000] + $preloading)));

        self::assertArrayNotHasKey('php_reload', $rows);
        self::assertNull($request->requestedAt());
    }

    public function testWithoutRevalidationAnOpcacheResetAlsoLoadsTheNewCode(): void
    {
        $request = new PhpReloadRequest($this->project);
        $request->request('release', 1_800_000_000);
        $runtime = $this->runtime(['validate_timestamps' => false, 'started_at' => 1_799_000_000]);

        self::assertArrayHasKey('php_reload', $this->rows($request->checks($runtime)));
        self::assertArrayNotHasKey('php_reload', $this->rows($request->checks(['restarted_at' => 1_800_000_100] + $runtime)));
        self::assertNull($request->requestedAt());
    }

    public function testAnotherApplicationsPreloadIsAWarningOfItsOwn(): void
    {
        $rows = $this->rows((new PhpReloadRequest($this->project))->checks($this->runtime(['preload' => '/var/www/other-site/config/preload.php'])));

        self::assertSame(SystemCheck::WARNING, $rows['opcache']['status']);
        self::assertStringContainsString('/var/www/other-site/config/preload.php, which is not part of this installation', $rows['opcache']['detail']);
        self::assertStringContainsString('App\Kernel', $rows['opcache']['detail']);
    }

    public function testTheCommandLineNeitherAnswersNorReportsTheRequest(): void
    {
        $request = new PhpReloadRequest($this->project);
        $request->request('release', 1_800_000_000);

        // OPcache is usually off for the command line, which is not the PHP serving the website.
        $rows = $this->rows($request->checks($this->runtime(['sapi' => 'cli', 'opcache' => false])));

        self::assertSame(['opcache'], array_keys($rows));
        self::assertSame(1_800_000_000, $request->requestedAt());
    }

    public function testAPhpThatHidesItsStatusKeepsTheRequestAndSaysHowToClearIt(): void
    {
        $request = new PhpReloadRequest($this->project);
        $request->request('release', 1_800_000_000);

        $rows = $this->rows($request->checks($this->runtime(['preload' => $this->project.'/config/preload.php'])));

        self::assertStringContainsString('cannot say whether it has reloaded', $rows['php_reload']['detail']);
        self::assertStringContainsString('delete var/php-reload-requested', $rows['php_reload']['detail']);
        self::assertSame(1_800_000_000, $request->requestedAt());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{sapi: string, opcache: bool, preload: string, validate_timestamps: bool, started_at: ?int, restarted_at: ?int}
     */
    private function runtime(array $overrides = []): array
    {
        return $overrides + ['sapi' => 'fpm-fcgi', 'opcache' => true, 'preload' => '', 'validate_timestamps' => true, 'started_at' => null, 'restarted_at' => null];
    }

    /**
     * @param list<array{id: string, label: string, status: string, detail: string}> $rows
     * @return array<string, array{id: string, label: string, status: string, detail: string}>
     */
    private function rows(array $rows): array
    {
        return array_column($rows, null, 'id');
    }
}
