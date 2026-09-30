<?php

declare(strict_types=1);

namespace App\Setup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One-time code that shows the person finishing a browser setup can read the
 * server's files. Bots watch newly issued HTTPS certificates and open fresh
 * installers within minutes; the code stops them from configuring a database
 * or creating the first administrator before the owner does.
 *
 * The code lives in SETUP-CODE.txt in the application directory, which the web
 * server cannot serve because the document root is public/. The file exists only
 * while a browser setup is pending and is removed when the first administrator
 * is created.
 *
 * Keep this class free of dependencies: config/setup.php loads it before Composer.
 */
final class SetupCode
{
    public const FILE = 'SETUP-CODE.txt';
    /** Carries the verified code from the setup page to /install in the same browser. */
    public const COOKIE = 'aggregate_setup_code';
    /** 32 symbols without 0/O and 1/I, so a code can be read back without confusion. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const PATTERN = '/\b([A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4})\b/';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function path(): string
    {
        return $this->projectDir.'/'.self::FILE;
    }

    /** Whether a browser setup is waiting for its code. */
    public function isPending(): bool
    {
        return is_file($this->path());
    }

    /** The current code, or null when no browser setup is pending. */
    public function current(): ?string
    {
        $contents = @file_get_contents($this->path(), false, null, 0, 4096);
        if (!is_string($contents) || preg_match(self::PATTERN, $contents, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Return the pending code, creating SETUP-CODE.txt on first use.
     *
     * @throws \RuntimeException when the file cannot be written
     */
    public function ensure(): string
    {
        $existing = $this->current();
        if ($existing !== null) {
            return $existing;
        }

        $code = self::generate();
        $contents = "Aggregate setup code\n\n    {$code}\n\n"
            ."Enter this code on the setup page in your browser. It shows that you manage\n"
            ."this server, so nobody else can finish setting up this installation first.\n"
            ."This file is deleted automatically when setup is complete.\n";

        // Exclusive creation: two first visits at once must agree on one code.
        $handle = @fopen($this->path(), 'x');
        if ($handle === false) {
            $existing = $this->current();
            if ($existing !== null) {
                return $existing;
            }
            if (is_file($this->path())) {
                // An unreadable or damaged file: replace it with a fresh code.
                @unlink($this->path());
                $handle = @fopen($this->path(), 'x');
            }
            if ($handle === false) {
                throw new \RuntimeException('The setup code file could not be created.');
            }
        }
        @chmod($this->path(), 0600);
        $written = fwrite($handle, $contents);
        fclose($handle);
        if ($written !== strlen($contents)) {
            @unlink($this->path());
            throw new \RuntimeException('The setup code file could not be written.');
        }

        return $code;
    }

    /** Whether the input matches the pending code; false when none is pending. */
    public function matches(mixed $input): bool
    {
        $code = $this->current();

        return $code !== null && is_string($input) && hash_equals($code, self::normalize($input));
    }

    /** Accept lowercase, spaces and missing dashes: "k7qm 2xpr9hdt" becomes "K7QM-2XPR-9HDT". */
    public static function normalize(string $input): string
    {
        $compact = preg_replace('/[\s\-]+/', '', strtoupper(substr($input, 0, 64))) ?? '';

        return strlen($compact) === 12 ? implode('-', str_split($compact, 4)) : $compact;
    }

    public function remove(): void
    {
        if (is_file($this->path())) {
            @unlink($this->path());
        }
    }

    private static function generate(): string
    {
        $symbols = '';
        for ($i = 0; $i < 12; ++$i) {
            $symbols .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($symbols, 4));
    }
}
