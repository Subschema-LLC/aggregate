<?php

declare(strict_types=1);

namespace App\Service\Update;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;

/** Finds the command-line programs an update runs: PHP, Composer and Git. */
class Toolchain
{
    public function __construct(private readonly string $projectDir)
    {
    }

    /** @return list<string>|null The command-line PHP binary and its arguments */
    public function php(): ?array
    {
        $finder = new PhpExecutableFinder();
        $php = $finder->find(false);

        return $php === false ? null : [$php, ...$finder->findArguments()];
    }

    /** @return list<string>|null */
    public function composer(): ?array
    {
        // Hosts that keep Composer off PATH (for example Plesk) can name it explicitly.
        $configured = $_SERVER['AGGREGATE_COMPOSER'] ?? $_ENV['AGGREGATE_COMPOSER'] ?? getenv('AGGREGATE_COMPOSER');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
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
