<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Dotenv\Dotenv;

/**
 * Reads and changes one variable in .env.local, the untracked file the setup
 * page writes and Symfony loads on every request. Other lines, comments and
 * the file's permissions are kept; the file is replaced in one step.
 *
 * A value saved here has no effect when the server environment, a later file
 * (.env.<environment>, .env.<environment>.local) or a compiled .env.local.php
 * sets the variable, so problem() reports those cases instead of saving.
 */
class LocalEnvironmentFile
{
    public const FILE = '.env.local';

    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
    ) {
    }

    /** Why $name cannot be saved to .env.local with effect, or null when it can. */
    public function problem(string $name): ?string
    {
        if (is_file($this->projectDir.'/.env.local.php')) {
            return 'This installation reads its environment from .env.local.php (made by composer dump-env), which ignores .env.local. Set '.$name.' by hand and run composer dump-env again, or delete .env.local.php.';
        }
        if ($this->setByServer($name)) {
            return $name.' is set in the server environment, which takes precedence over .env.local. Change it there.';
        }
        foreach (['.env.'.$this->environment, '.env.'.$this->environment.'.local'] as $file) {
            if (array_key_exists($name, $this->parse($this->projectDir.'/'.$file))) {
                return $name.' is set in '.$file.', which takes precedence over .env.local. Change it there.';
            }
        }
        $file = $this->path();
        if (is_file($file) ? !is_writable($file) : !is_writable($this->projectDir)) {
            return '.env.local cannot be written by the user the web server runs as. Make it writable by that user, or set '.$name.' in it by hand.';
        }

        return null;
    }

    /** The value in .env.local, or null when the file does not set it. */
    public function get(string $name): ?string
    {
        $value = $this->parse($this->path())[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Sets $name to $value in .env.local, or removes it with null.
     *
     * @throws \InvalidArgumentException when the value cannot be written safely
     * @throws \RuntimeException         when the file cannot be written
     */
    public function set(string $name, ?string $value, string $comment = ''): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Not an environment variable name.');
        }
        if ($value !== null && ($value === '' || preg_match('/[\x00-\x1f\x7f\'\\\\]/', $value) === 1)) {
            throw new \InvalidArgumentException('The value is empty or contains a quote, a backslash or a control character, which .env.local cannot hold safely.');
        }
        $file = $this->path();
        $current = is_file($file) ? @file_get_contents($file) : '';
        if ($current === false) {
            throw new \RuntimeException('.env.local could not be read.');
        }

        // Drop every assignment of this name, with the comment line saved with it.
        $lines = $current === '' ? [] : explode("\n", rtrim($current, "\n"));
        $pattern = '/^\s*(?:export\s+)?'.preg_quote($name, '/').'\s*=/';
        $kept = [];
        foreach ($lines as $line) {
            if (preg_match($pattern, $line) === 1) {
                if ($kept !== [] && $comment !== '' && end($kept) === '# '.$comment) {
                    array_pop($kept);
                }
                continue;
            }
            $kept[] = $line;
        }
        if ($value !== null) {
            if ($comment !== '') {
                $kept[] = '# '.$comment;
            }
            $kept[] = $name."='".$value."'";
        }
        $this->write($file, $kept === [] ? '' : implode("\n", $kept)."\n");
    }

    public function path(): string
    {
        return $this->projectDir.'/'.self::FILE;
    }

    /** Set outside the .env files: in the server's or process's own environment. */
    private function setByServer(string $name): bool
    {
        $loaded = $_SERVER['SYMFONY_DOTENV_VARS'] ?? $_ENV['SYMFONY_DOTENV_VARS'] ?? '';
        $fromFiles = is_string($loaded) && in_array($name, explode(',', $loaded), true);
        $present = isset($_SERVER[$name]) || isset($_ENV[$name]) || getenv($name) !== false;

        return $present && !$fromFiles;
    }

    /** @return array<string, string> */
    private function parse(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        try {
            return (new Dotenv())->parse((string) file_get_contents($file), $file);
        } catch (\Throwable) {
            return [];
        }
    }

    private function write(string $file, string $contents): void
    {
        $exists = is_file($file);
        $mode = $exists ? (fileperms($file) & 0777) : 0600;
        $temporary = @tempnam($this->projectDir, '.env.local.');
        if ($temporary !== false) {
            // A replacement must keep the owner and group the web server reads
            // it as, for example when an administrator runs a command as root.
            $sameOwner = !$exists || (@fileowner($temporary) === @fileowner($file)
                && (@filegroup($temporary) === @filegroup($file) || @chgrp($temporary, (int) @filegroup($file))));
            if ($sameOwner && @file_put_contents($temporary, $contents) === strlen($contents) && @chmod($temporary, $mode) && @rename($temporary, $file)) {
                return;
            }
            @unlink($temporary);
        }
        // Otherwise, or without write access to the folder, rewrite the file in place.
        if (@file_put_contents($file, $contents, LOCK_EX) !== strlen($contents)) {
            throw new \RuntimeException('.env.local could not be written. Check that the web server’s user can write it.');
        }
    }
}
