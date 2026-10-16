<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LocalEnvironmentFile;
use PHPUnit\Framework\TestCase;

final class LocalEnvironmentFileTest extends TestCase
{
    private const NAME = 'AGGREGATE_LOCAL_FILE_TEST_URL';

    private string $projectDir;
    private array $environment;

    protected function setUp(): void
    {
        $this->environment = [$_ENV, $_SERVER];
        unset($_ENV[self::NAME], $_SERVER[self::NAME]);
        $this->projectDir = sys_get_temp_dir().'/aggregate-env-local-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0700);
    }

    protected function tearDown(): void
    {
        [$_ENV, $_SERVER] = $this->environment;
        @chmod($this->projectDir, 0700);
        array_map(unlink(...), glob($this->projectDir.'/{,.}[!.]*', GLOB_BRACE) ?: []);
        rmdir($this->projectDir);
    }

    public function testAValueReplacesEarlierAssignmentsAndKeepsOtherLines(): void
    {
        $file = $this->projectDir.'/.env.local';
        file_put_contents($file, "# Written by the setup page\nDATABASE_URL='sqlite:///%kernel.project_dir%/var/data.db'\n# Old\n".self::NAME."=mysql://old\nAPP_SECRET=abc\n");
        chmod($file, 0640);
        $env = $this->file();

        $env->set(self::NAME, 'postgresql://u:p%40ss@db:5432/r', 'Reporting database');
        self::assertSame(
            "# Written by the setup page\nDATABASE_URL='sqlite:///%kernel.project_dir%/var/data.db'\n# Old\nAPP_SECRET=abc\n# Reporting database\n".self::NAME."='postgresql://u:p%40ss@db:5432/r'\n",
            file_get_contents($file),
        );
        self::assertSame('postgresql://u:p%40ss@db:5432/r', $env->get(self::NAME));
        self::assertSame(0640, fileperms($file) & 0777);

        // Saving again replaces the comment too; removing drops both lines.
        $env->set(self::NAME, 'sqlite:///%kernel.project_dir%/var/reporting.db', 'Reporting database');
        self::assertSame(1, substr_count((string) file_get_contents($file), '# Reporting database'));
        $env->set(self::NAME, null, 'Reporting database');
        self::assertSame("# Written by the setup page\nDATABASE_URL='sqlite:///%kernel.project_dir%/var/data.db'\n# Old\nAPP_SECRET=abc\n", file_get_contents($file));
        self::assertNull($env->get(self::NAME));
        self::assertSame([], glob($this->projectDir.'/.env.local.*'));
    }

    public function testANewFileIsPrivate(): void
    {
        $this->file()->set(self::NAME, 'sqlite:///tmp/r.db');

        self::assertSame(self::NAME."='sqlite:///tmp/r.db'\n", file_get_contents($this->projectDir.'/.env.local'));
        self::assertSame(0600, fileperms($this->projectDir.'/.env.local') & 0777);
    }

    public function testARunAsAnotherUserKeepsTheFilesOwnerSoTheWebServerCanStillReadIt(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Needs root to give the file to another user.');
        }
        $file = $this->projectDir.'/.env.local';
        file_put_contents($file, "APP_SECRET=abc\n");
        chown($file, 'nobody');
        chmod($file, 0640);

        $this->file()->set(self::NAME, 'sqlite:///tmp/r.db');

        clearstatcache();
        self::assertSame('nobody', posix_getpwuid((int) fileowner($file))['name']);
        self::assertSame(0640, fileperms($file) & 0777);
        self::assertSame("APP_SECRET=abc\n".self::NAME."='sqlite:///tmp/r.db'\n", file_get_contents($file));
    }

    public function testValuesThatCannotBeQuotedSafelyAreRefused(): void
    {
        foreach (['', "a'b", 'a\\b', "a\nB=c"] as $value) {
            try {
                $this->file()->set(self::NAME, $value);
                self::fail('Saved '.json_encode($value));
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertFileDoesNotExist($this->projectDir.'/.env.local');
    }

    public function testItSaysWhenASavedValueWouldHaveNoEffect(): void
    {
        self::assertNull($this->file()->problem(self::NAME));

        $_SERVER[self::NAME] = 'mysql://server';
        $_SERVER['SYMFONY_DOTENV_VARS'] = 'APP_ENV';
        self::assertStringContainsString('set in the server environment', (string) $this->file()->problem(self::NAME));
        // A value loaded from the .env files is not the server's.
        $_SERVER['SYMFONY_DOTENV_VARS'] = 'APP_ENV,'.self::NAME;
        self::assertNull($this->file()->problem(self::NAME));
        unset($_SERVER[self::NAME]);

        file_put_contents($this->projectDir.'/.env.prod.local', self::NAME."=mysql://later\n");
        self::assertStringContainsString('set in .env.prod.local', (string) $this->file()->problem(self::NAME));
        unlink($this->projectDir.'/.env.prod.local');

        file_put_contents($this->projectDir.'/.env.local.php', '<?php return [];');
        self::assertStringContainsString('.env.local.php', (string) $this->file()->problem(self::NAME));
        unlink($this->projectDir.'/.env.local.php');

        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            chmod($this->projectDir, 0500);
            self::assertStringContainsString('cannot be written', (string) $this->file()->problem(self::NAME));
        }
    }

    private function file(): LocalEnvironmentFile
    {
        return new LocalEnvironmentFile($this->projectDir, 'prod');
    }
}
