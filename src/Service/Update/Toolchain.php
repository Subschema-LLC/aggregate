<?php

declare(strict_types=1);

namespace App\Service\Update;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/** Finds the command-line programs an update runs: PHP, Composer and Git. */
class Toolchain
{
    /** Pdo drivers whose presence a command-line PHP must match. */
    private const DRIVER_EXTENSIONS = ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite', 'pdo_sqlsrv', 'sqlsrv'];

    /** @var array{0: list<string>|null, 1: ?string}|null */
    private ?array $php = null;

    /**
     * @param string            $sapi          this PHP's SAPI; tests stand in for a web server
     * @param list<string>|null $phpCandidates where to look for the command-line PHP instead of the usual places (tests)
     */
    public function __construct(
        private readonly string $projectDir,
        private readonly string $sapi = \PHP_SAPI,
        private readonly ?array $phpCandidates = null,
    ) {
    }

    /**
     * The command-line PHP binary (and its arguments) for console commands
     * started from here. On the command line it is this PHP. From the website
     * it is a binary that runs the same PHP version with the extensions this
     * PHP has: PHP_PATH (server environment or .env.local) when set, otherwise
     * the one beside this PHP or another common location. Each candidate is
     * run once to check, because open_basedir, the default on Plesk, hides
     * binaries from file checks.
     *
     * @return list<string>|null
     */
    public function php(): ?array
    {
        return ($this->php ??= $this->findPhp())[0];
    }

    /** Why php() found no usable binary, for the administrator; null when it found one. */
    public function phpProblem(): ?string
    {
        return ($this->php ??= $this->findPhp())[1];
    }

    /**
     * Another PHP binary on this server that has every given extension and at
     * least $minimumVersion, for advice when the command-line PHP lacks them.
     * The same minor version as this PHP comes first.
     *
     * @param list<string> $extensions
     */
    public function suggestPhp(array $extensions, string $minimumVersion): ?string
    {
        $fallback = null;
        foreach ($this->phpCandidates(includeOtherVersions: true) as $candidate) {
            if ($candidate === \PHP_BINARY || @realpath($candidate) === @realpath(\PHP_BINARY)) {
                continue;
            }
            $probe = $this->probe($candidate, $extensions);
            if ($probe === null || $probe['missing'] !== [] || version_compare($probe['version'].'.99', $minimumVersion, '<')) {
                continue;
            }
            if ($probe['version'] === \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION) {
                return $candidate;
            }
            $fallback ??= $candidate;
        }

        return $fallback;
    }

    /** @return array{0: list<string>|null, 1: ?string} */
    private function findPhp(): array
    {
        // The command line, and PHP's built-in web server, run the CLI binary itself.
        if (\PHP_BINARY !== '' && \in_array($this->sapi, ['cli', 'cli-server', 'phpdbg'], true)) {
            return [[\PHP_BINARY, ...(new PhpExecutableFinder())->findArguments()], null];
        }
        $version = \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION;
        $wanted = array_values(array_filter([...SystemCheck::REQUIRED_EXTENSIONS, ...self::DRIVER_EXTENSIONS], 'extension_loaded'));
        $configured = self::setting('PHP_PATH');
        $candidates = $configured !== null ? [$configured] : $this->phpCandidates();
        $near = [];
        foreach ($candidates as $candidate) {
            $probe = $this->probe($candidate, $wanted);
            if ($probe !== null && $probe['version'] === $version && $probe['missing'] === []) {
                return [[$candidate], null];
            }
            if ($probe !== null) {
                $near[] = $candidate.' (PHP '.$probe['version'].($probe['missing'] === [] ? '' : ', without '.implode(', ', $probe['missing'])).')';
            }
        }
        $example = $this->examplePath($version);
        if ($configured !== null) {
            return [null, 'PHP_PATH names '.($near === [] ? $configured.', which could not be run' : $near[0].', which does not match this website\'s PHP '.$version).'. Point PHP_PATH in .env.local at the PHP '.$version.' command-line binary, for example '.$example.'.'];
        }

        return [null, ($near === []
            ? 'The command-line PHP '.$version.' executable could not be found'.(\ini_get('open_basedir') ? ' (open_basedir hides it from the website)' : '').'.'
            : 'The command-line PHP found here does not match this website\'s PHP '.$version.': '.implode('; ', $near).'.')
            .' Set PHP_PATH in .env.local to the PHP '.$version.' command-line binary, for example '.$example.'.'];
    }

    /**
     * Where the command-line PHP usually is: Symfony's guess, the binary beside
     * this one (PHP-FPM keeps it in ../bin), and the locations of common
     * hosting panels and distributions.
     *
     * @return list<string>
     */
    private function phpCandidates(bool $includeOtherVersions = false): array
    {
        if ($this->phpCandidates !== null) {
            return $this->phpCandidates;
        }
        $version = \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION;
        $versions = [$version];
        if ($includeOtherVersions) {
            foreach ([...(glob('/opt/plesk/php/*/bin/php') ?: []), ...(glob('/usr/bin/php[0-9].[0-9]') ?: [])] as $path) {
                if (preg_match('#(\d+\.\d+)#', $path, $match) === 1) {
                    $versions[] = $match[1];
                }
            }
            $versions = array_values(array_unique($versions));
        }
        $candidates = [];
        $symfony = (new PhpExecutableFinder())->find(false);
        if (is_string($symfony) && $symfony !== '') {
            $candidates[] = $symfony;
        }
        if (\PHP_BINARY !== '') {
            $candidates[] = dirname(\PHP_BINARY, 2).'/bin/php';
        }
        $candidates[] = \PHP_BINDIR.'/php';
        foreach ($versions as $each) {
            $short = str_replace('.', '', $each);
            array_push($candidates,
                '/opt/plesk/php/'.$each.'/bin/php',
                \PHP_BINDIR.'/php'.$each,
                '/usr/bin/php'.$each,
                '/opt/cpanel/ea-php'.$short.'/root/usr/bin/php',
                '/opt/alt/php'.$short.'/usr/bin/php',
            );
        }
        array_push($candidates, '/usr/local/bin/php', '/usr/bin/php');

        return array_values(array_unique($candidates));
    }

    /**
     * Runs $binary to read its version and which of $extensions it lacks.
     *
     * @param list<string> $extensions
     *
     * @return array{version: string, missing: list<string>}|null null when it does not run as PHP
     */
    private function probe(string $binary, array $extensions): ?array
    {
        // Without open_basedir a missing file is known without running it.
        if (!\ini_get('open_basedir') && !(@is_file($binary) && @is_executable($binary))) {
            return null;
        }
        if (!\function_exists('proc_open')) {
            return @is_file($binary) && @is_executable($binary) ? ['version' => \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION, 'missing' => []] : null;
        }
        $code = 'echo json_encode([PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION, array_values(array_filter('.var_export($extensions, true).', fn ($e) => !extension_loaded($e)))]);';
        try {
            $process = new Process([$binary, '-d', 'display_errors=stderr', '-r', $code], is_dir($this->projectDir) ? $this->projectDir : null, null, null, 15);
            $process->run();
        } catch (\Throwable) {
            return null;
        }
        $result = $process->isSuccessful() ? json_decode(trim($process->getOutput()), true) : null;
        if (!\is_array($result) || !\is_string($result[0] ?? null) || preg_match('/^\d+\.\d+$/D', $result[0]) !== 1 || !\is_array($result[1] ?? null)) {
            return null;
        }

        return ['version' => $result[0], 'missing' => array_values(array_filter($result[1], 'is_string'))];
    }

    private function examplePath(string $version): string
    {
        if (\PHP_BINARY !== '' && str_contains(\PHP_BINARY, '/opt/plesk/php/')) {
            return '/opt/plesk/php/'.$version.'/bin/php';
        }

        return \PHP_BINDIR.'/php';
    }

    /** A setting from the server environment or .env.local, which Symfony loads into $_SERVER. */
    private static function setting(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return list<string>|null */
    public function composer(): ?array
    {
        // Hosts that keep Composer off PATH (common on hosting panels) can name it explicitly.
        $configured = self::setting('AGGREGATE_COMPOSER');
        if ($configured !== null && is_file($configured)) {
            if (str_ends_with($configured, '.phar')) {
                $php = $this->php();

                return $php === null ? null : [...$php, $configured];
            }

            return is_executable($configured) ? [$configured] : null;
        }
        if (is_file($this->projectDir.'/composer.phar')) {
            $php = $this->php();

            return $php === null ? null : [...$php, $this->projectDir.'/composer.phar'];
        }
        $composer = (new ExecutableFinder())->find('composer');

        return $composer === null ? null : [$composer];
    }

    public function git(): ?string
    {
        return (new ExecutableFinder())->find('git');
    }
}
