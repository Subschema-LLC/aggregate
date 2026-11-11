<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Asks for PHP to be reloaded after an update, and tells whether it was.
 *
 * While OPcache preloads Aggregate's classes, or does not revalidate file
 * timestamps, PHP keeps running the code from before an in-place update until
 * PHP-FPM (or the web server) reloads, and an update run from the command line
 * cannot reload it. Every finished update writes var/php-reload-requested: a
 * hosting panel or a systemd path unit can watch for it, reload PHP-FPM
 * gracefully and delete it. The Updates page deletes it too, once PHP has
 * started again since, and otherwise says that a reload is still needed.
 */
class PhpReloadRequest
{
    public const FILE = 'var/php-reload-requested';

    public function __construct(private readonly string $projectDir)
    {
    }

    public function path(): string
    {
        return $this->projectDir.'/'.self::FILE;
    }

    /** Written when an update finishes; a missing var/ is not an error. */
    public function request(string $reason, ?int $now = null): void
    {
        $now ??= time();
        $written = @file_put_contents($this->path(), json_encode(['requested_at' => $now, 'reason' => $reason], JSON_THROW_ON_ERROR)."\n", LOCK_EX);
        if ($written !== false) {
            @chmod($this->path(), 0664);
        }
    }

    public function requestedAt(): ?int
    {
        if (!is_file($this->path())) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->path()), true);
        $at = is_array($data) ? ($data['requested_at'] ?? null) : null;

        // An unreadable request still asks for a reload: date it by the file.
        return is_int($at) ? $at : (@filemtime($this->path()) ?: null);
    }

    public function clear(): void
    {
        @unlink($this->path());
    }

    /**
     * What the running PHP does that matters after an update.
     *
     * @return array{sapi: string, opcache: bool, preload: string, validate_timestamps: bool, started_at: ?int, restarted_at: ?int}
     */
    public static function runtime(): array
    {
        $opcache = function_exists('opcache_get_status') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)
            && (\PHP_SAPI !== 'cli' || filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL));
        // opcache.restrict_api can refuse the status; then the times are unknown.
        $status = $opcache ? @opcache_get_status(false) : false;
        $statistics = is_array($status) ? ($status['opcache_statistics'] ?? []) : [];

        return [
            'sapi' => \PHP_SAPI,
            'opcache' => $opcache,
            'preload' => $opcache ? trim((string) ini_get('opcache.preload')) : '',
            'validate_timestamps' => filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOL),
            'started_at' => is_int($statistics['start_time'] ?? null) && $statistics['start_time'] > 0 ? $statistics['start_time'] : null,
            'restarted_at' => is_int($statistics['last_restart_time'] ?? null) && $statistics['last_restart_time'] > 0 ? $statistics['last_restart_time'] : null,
        ];
    }

    /**
     * The system check's rows about OPcache, preloading and a pending reload.
     * Run by the web server, it also deletes a request PHP has already
     * answered, or one this PHP does not need.
     *
     * @param array{sapi: string, opcache: bool, preload: string, validate_timestamps: bool, started_at: ?int, restarted_at: ?int}|null $runtime
     * @return list<array{id: string, label: string, status: string, detail: string}>
     */
    public function checks(?array $runtime = null): array
    {
        $runtime ??= self::runtime();
        $rows = [];
        $preload = $runtime['preload'];
        $ownPreload = $preload !== '' && self::isWithin($preload, $this->projectDir);
        $keepsOldCode = $runtime['opcache'] && ($preload !== '' || !$runtime['validate_timestamps']);

        if ($preload !== '' && !$ownPreload) {
            $rows[] = ['id' => 'opcache', 'label' => 'PHP OPcache', 'status' => SystemCheck::WARNING,
                'detail' => 'OPcache preloads '.$preload.', which is not part of this installation. Every site this PHP-FPM serves gets those classes, and any with the same name as one of Aggregate\'s, such as App\Kernel, replaces it. Serve Aggregate from a PHP-FPM that preloads nothing or its own config/preload.php.'];
        } elseif ($ownPreload) {
            $rows[] = ['id' => 'opcache', 'label' => 'PHP OPcache', 'status' => SystemCheck::WARNING,
                'detail' => 'OPcache preloads Aggregate\'s classes, so PHP keeps running the code from before an update until PHP-FPM reloads. Reload it after each update, or let a hosting panel do so when '.self::FILE.' appears.'];
        } elseif ($keepsOldCode) {
            $rows[] = ['id' => 'opcache', 'label' => 'PHP OPcache', 'status' => SystemCheck::WARNING,
                'detail' => 'opcache.validate_timestamps is off, so PHP keeps running old code after an update until PHP is reloaded (for example PHP-FPM or the web server). Reload it after each update, or let a hosting panel do so when '.self::FILE.' appears.'];
        } else {
            $rows[] = ['id' => 'opcache', 'label' => 'PHP OPcache', 'status' => SystemCheck::OK, 'detail' => 'PHP picks up changed files after an update.'];
        }

        // The command line's PHP is not the one serving the website.
        if ($runtime['sapi'] === 'cli') {
            return $rows;
        }
        $requestedAt = $this->requestedAt();
        if ($requestedAt === null) {
            return $rows;
        }
        // Preloaded classes are loaded again only when PHP starts; other
        // cached code also after an OPcache reset.
        $since = $preload !== '' ? $runtime['started_at'] : max($runtime['started_at'] ?? 0, $runtime['restarted_at'] ?? 0);
        // A panel watching for the request can reload within the same second.
        if (!$keepsOldCode || ($since !== null && $since >= $requestedAt)) {
            $this->clear();

            return $rows;
        }
        $rows[] = ['id' => 'php_reload', 'label' => 'PHP reloaded after the update', 'status' => SystemCheck::WARNING,
            'detail' => ($since === null
                ? 'An update finished at '.date('Y-m-d H:i T', $requestedAt).', and this PHP cannot say whether it has reloaded since (opcache.restrict_api). Reload PHP-FPM (for example sudo systemctl reload php'.\PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION.'-fpm) or the web server, then delete '.self::FILE.'.'
                : 'Not yet: PHP last started before the update that finished at '.date('Y-m-d H:i T', $requestedAt).', so it still runs the code from before it. Reload PHP-FPM (for example sudo systemctl reload php'.\PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION.'-fpm) or the web server.')];

        return $rows;
    }

    private static function isWithin(string $path, string $directory): bool
    {
        $resolvedPath = realpath($path) ?: $path;
        $resolvedDirectory = rtrim(realpath($directory) ?: $directory, '/').'/';

        return str_starts_with($resolvedPath, $resolvedDirectory);
    }
}
