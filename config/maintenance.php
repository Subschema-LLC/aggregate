<?php

declare(strict_types=1);

/*
 * Update maintenance gate, loaded by public/index.php before Composer or the
 * kernel. While an update replaces application files, every web request gets
 * a 503 response instead of running a partially updated application.
 *
 * var/maintenance.json is written by App\Service\Update\MaintenanceMode.
 * It stays active until it is removed, until its expires_at time passes, or
 * while the updater still holds var/updates/update.lock. Keep this file
 * dependency-free: it must work with old and new application code.
 */

return static function (string $projectDir): bool {
    $file = $projectDir.'/var/maintenance.json';
    if (!is_file($file)) {
        return false;
    }
    $raw = @file_get_contents($file, false, null, 0, 65536);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($state)) {
        // Fail closed: an unreadable marker still means an update is in progress.
        $state = [];
    }

    $expiresAt = $state['expires_at'] ?? null;
    if (is_int($expiresAt) && $expiresAt < time()) {
        $lock = @fopen($projectDir.'/var/updates/update.lock', 'r');
        $running = false;
        if ($lock !== false) {
            $running = !flock($lock, LOCK_SH | LOCK_NB);
            if (!$running) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
        if (!$running) {
            return false;
        }
    }

    $step = null;
    $progress = @file_get_contents($projectDir.'/var/updates/state.json', false, null, 0, 1048576);
    $journal = is_string($progress) ? json_decode($progress, true) : null;
    $labels = [
        'download' => 'Downloading the update',
        'verify' => 'Verifying the update',
        'stage' => 'Preparing files',
        'database_backup' => 'Backing up the database',
        'maintenance' => 'Pausing the application',
        'apply_files' => 'Installing application files',
        'dependencies' => 'Installing dependencies',
        'handoff' => 'Starting the updated application',
        'migrations' => 'Updating the database',
        'glossary' => 'Publishing reporting labels',
        'assets' => 'Building dashboard assets',
        'cache' => 'Rebuilding the application cache',
        'workers' => 'Restarting background workers',
        'finish' => 'Finishing',
        'rollback' => 'Restoring the previous version',
    ];
    if (is_array($journal) && is_string($journal['step'] ?? null) && isset($labels[$journal['step']])) {
        $step = $labels[$journal['step']];
    }
    $failed = is_array($journal) && in_array($journal['status'] ?? null, ['failed', 'needs_attention'], true);

    $retryAfter = 60;
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: '.$retryAfter);
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
    }
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_string($path) && (str_starts_with($path, '/api/') || $path === '/api')) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['status' => 'maintenance', 'message' => 'An application update is in progress. Retry later.'], JSON_UNESCAPED_SLASHES);

        return true;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    $message = $failed
        ? 'The update needs attention from an administrator. The site will return once it is resolved.'
        : 'An update is being installed. This page refreshes automatically.';
    $detail = $step !== null && !$failed ? '<p class="step">'.htmlspecialchars($step, ENT_QUOTES).'…</p>' : '';
    $refresh = $failed ? '' : '<meta http-equiv="refresh" content="15">';
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
{$refresh}
<title>Maintenance</title>
<style>
:root { color-scheme: light dark; --bg: #f7f7f8; --fg: #1f2328; --muted: #57606a; --card: #ffffff; --line: #d0d7de; }
@media (prefers-color-scheme: dark) { :root { --bg: #16181d; --fg: #e6edf3; --muted: #9da7b3; --card: #1f232b; --line: #30363d; } }
body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--fg); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 32rem; margin: 1rem; padding: 2rem; background: var(--card); border: 1px solid var(--line); border-radius: 12px; }
h1 { font-size: 1.4rem; margin: 0 0 .5rem; }
p { margin: .5rem 0; color: var(--muted); }
.step { color: var(--fg); font-weight: 600; }
</style>
</head>
<body>
<main role="status" aria-live="polite">
<h1>Down for maintenance</h1>
<p>{$message}</p>
{$detail}
</main>
</body>
</html>
HTML;

    return true;
};
