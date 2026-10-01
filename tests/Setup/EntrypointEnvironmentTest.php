<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Service\Update\LocalConfigOverrides;
use App\Service\Update\ReleasePackageInstaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A Git checkout or a hosting panel's Git deployment has no .env (it is not in
 * the repository), only the .env.local written by setup. Both entry points must
 * then point Symfony Runtime at .env.local, whose dotenv_path is relative to
 * the project directory.
 */
final class EntrypointEnvironmentTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/aggregate-entry-'.bin2hex(random_bytes(8));
        foreach (['bin', 'public', 'vendor'] as $directory) {
            mkdir($this->project.'/'.$directory, 0775, true);
        }
        $root = dirname(__DIR__, 2);
        copy($root.'/bin/console', $this->project.'/bin/console');
        copy($root.'/public/index.php', $this->project.'/public/index.php');
        // Stands in for Symfony Runtime: report the options it would receive.
        file_put_contents($this->project.'/vendor/autoload_runtime.php', '<?php echo json_encode($_SERVER["APP_RUNTIME_OPTIONS"] ?? null); exit(0);');
        file_put_contents($this->project.'/.env.local', "APP_ENV=prod\n");
    }

    protected function tearDown(): void
    {
        (new ReleasePackageInstaller($this->project, new LocalConfigOverrides($this->project)))->removeTree($this->project);
    }

    #[DataProvider('entrypoints')]
    public function testWithoutDotEnvTheLocalFileIsLoadedFromTheProjectDirectory(string $entrypoint): void
    {
        $php = (new PhpExecutableFinder())->find(false);
        self::assertIsString($php);
        $process = new Process([$php, $this->project.'/'.$entrypoint], sys_get_temp_dir(), timeout: 30);
        $process->mustRun();
        $options = json_decode($process->getOutput(), true);

        self::assertIsArray($options, $process->getOutput());
        self::assertFileExists($this->project.'/'.$options['dotenv_path'], 'Symfony Runtime prefixes dotenv_path with the project directory.');
        self::assertSame('.env.local', $options['dotenv_path']);
    }

    public static function entrypoints(): iterable
    {
        yield 'console' => ['bin/console'];
        yield 'web' => ['public/index.php'];
    }
}
