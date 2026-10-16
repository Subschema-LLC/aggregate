<?php

namespace App\Service;

/**
 * Remembers the result of parsing and validating a YAML document, keyed by the
 * document's exact contents, in PHP files that OPcache keeps in memory.
 *
 * Settings stay in YAML. Every read still opens and reads the file, so any
 * change, from the dashboard, the command line or an editor, gives new
 * contents, a new key and a fresh parse; only an identical document reuses a
 * result. Entries are written atomically under new names, so OPcache never
 * serves a half-written or outdated entry, even with
 * opcache.validate_timestamps=0. A document that fails to parse or validate is
 * never cached, so it fails the same way on every request. Caching is best
 * effort: when the directory cannot be written, documents are parsed as before.
 *
 * Parsing config/aggregate.yaml was about a third of the PHP time of a
 * collection request (see docs/LOAD-TESTING.md).
 */
final class ParsedYamlCache
{
    /** Changes when the cached shape changes, so old entries are ignored. */
    private const FORMAT = 1;

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * The default location for code that is constructed without the container:
     * var/cache/ENVIRONMENT/parsed-yaml, the same place the container uses, so
     * clearing the cache clears it too.
     */
    public static function forProject(string $projectDir, ?string $environment = null): self
    {
        return new self(rtrim($projectDir, '/\\').'/var/cache/'.($environment !== null ? $environment.'/' : '').'parsed-yaml');
    }

    /**
     * @param string                         $name  what the document is, such as "aggregate" or "websites"; one cache entry is kept per name
     * @param callable(string): array<mixed> $parse parses and validates the document; its exceptions pass through and nothing is cached
     *
     * @return array<mixed>
     */
    public function remember(string $name, string $yaml, callable $parse): array
    {
        $prefix = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
        $file = $this->directory.'/'.$prefix.'.'.hash('xxh128', self::FORMAT."\0".$name."\0".$yaml).'.php';

        if (is_file($file)) {
            $cached = @include $file;
            if (is_array($cached) && ($cached['format'] ?? null) === self::FORMAT && is_array($cached['data'] ?? null)) {
                return $cached['data'];
            }
        }

        $data = $parse($yaml);
        $this->store($file, $prefix, $data);

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private function store(string $file, string $prefix, array $data): void
    {
        try {
            if (!is_dir($this->directory) && !@mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
                return;
            }

            $code = '<?php return '.var_export(['format' => self::FORMAT, 'data' => $data], true).";\n";
            $temporary = @tempnam($this->directory, '.'.$prefix.'.');
            if ($temporary === false) {
                return;
            }
            if (@file_put_contents($temporary, $code) !== strlen($code)) {
                @unlink($temporary);

                return;
            }
            // tempnam() creates 0600 files; use the usual permissions so that a
            // command run by another user leaves an entry the web server can read.
            @chmod($temporary, 0666 & ~umask());
            if (!@rename($temporary, $file)) {
                @unlink($temporary);

                return;
            }

            // Keep one entry per document.
            foreach (glob($this->directory.'/'.$prefix.'.*.php') ?: [] as $old) {
                if ($old !== $file) {
                    @unlink($old);
                }
            }
        } catch (\Throwable) {
            // Best effort: the parsed result has already been returned to the caller.
        }
    }
}
