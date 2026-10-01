#!/usr/bin/env php
<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Last-resort file recovery for a release update that stopped while files were
// being replaced, for when bin/console itself can no longer start. It loads no
// framework code: only the self-contained FileTransaction class.
// Normal recovery: php bin/console app:updates:rollback

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$projectDir = dirname(__DIR__);
$statePath = $projectDir.'/var/updates/state.json';
$state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
if (!is_array($state) || !is_string($state['backup'] ?? null) || !is_string($state['type'] ?? null)) {
    fwrite(STDERR, "No recorded update was found in var/updates/state.json.\n");
    exit(1);
}
if (($state['status'] ?? null) === 'rolled_back') {
    fwrite(STDERR, "The last update was already rolled back.\n");
    exit(1);
}

$lock = @fopen($projectDir.'/var/updates/update.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "An update is still running. Wait for it to stop first.\n");
    exit(1);
}

// Keep visitors on the maintenance page until an administrator finishes recovery.
@file_put_contents($projectDir.'/var/maintenance.json', json_encode(['since' => time(), 'expires_at' => null, 'reason' => 'rollback'])."\n");

if ($state['type'] === 'deployment') {
    $commit = is_string($state['from']['commit'] ?? null) ? $state['from']['commit'] : 'the previous commit';
    fwrite(STDOUT, "Your deployment tool installed these files, so there is no file backup here.\n"
        ."Deploy {$commit} again with that tool. Its deployment action then finishes the\n"
        ."deployment and turns maintenance mode off. To turn it off by hand:\n\n"
        ."  rm -rf var/cache/*\n"
        ."  php bin/console app:updates:maintenance off\n");
    exit(0);
}
if ($state['type'] !== 'release') {
    $commit = is_string($state['from']['commit'] ?? null) ? $state['from']['commit'] : 'PREVIOUS_COMMIT';
    fwrite(STDOUT, "This is a Git checkout. Restore the previous code with:\n\n"
        ."  git reset --keep {$commit}\n"
        ."  composer install --no-dev --optimize-autoloader\n"
        ."  rm -rf var/cache/*\n"
        ."  php bin/console cache:clear\n"
        ."  php bin/console app:updates:maintenance off\n");
    exit(0);
}

require $projectDir.'/src/Service/Update/FileTransaction.php';

try {
    $result = (new App\Service\Update\FileTransaction($projectDir, $projectDir.'/'.$state['backup']))->rollback();
} catch (\Throwable $e) {
    fwrite(STDERR, 'File recovery stopped: '.$e->getMessage()."\n");
    exit(1);
}

foreach (glob($projectDir.'/var/cache/*', GLOB_ONLYDIR) ?: [] as $cache) {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($cache);
}

$state['status'] = 'rolled_back';
$state['step'] = null;
$state['log'][] = ['at' => time(), 'level' => 'warning', 'message' => 'Files restored with scripts/restore-update-files.php.'];
@file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

fwrite(STDOUT, sprintf("Restored %d files and removed %d files added by the update.\n", $result['restored'], $result['removed']));
fwrite(STDOUT, "Database changes were not reversed. If migrations ran, restore your database backup.\n"
    ."Then rebuild the cache and leave maintenance mode:\n\n"
    ."  php bin/console cache:clear\n"
    ."  php bin/console app:updates:maintenance off\n");
exit(0);
