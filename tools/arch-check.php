<?php

declare(strict_types=1);

/*
 * Checks the FIDES rules a machine can check (docs/FIDES-MIGRATION.md).
 * Needs nothing but PHP: it reads source files without loading them.
 *
 *   php tools/arch-check.php                    check; exit 1 on any problem
 *   php tools/arch-check.php --update-baseline  record findings that went away
 *
 * Code in src/Entities, src/Features, src/Integrations, src/Delivery and
 * src/Shared must follow every rule. Other code is held to the baseline in
 * tools/arch-check-baseline.json: findings may go away, never appear. The
 * update only removes findings; it never admits a new one.
 */

use App\Tools\ArchCheck\Ratchet;
use App\Tools\ArchCheck\SourceFile;
use App\Tools\ArchCheck\ZoneRules;

require __DIR__.'/ArchCheck/SourceFile.php';
require __DIR__.'/ArchCheck/ZoneRules.php';
require __DIR__.'/ArchCheck/Ratchet.php';

$root = dirname(__DIR__);
$baselineFile = __DIR__.'/arch-check-baseline.json';
$update = in_array('--update-baseline', $argv, true);

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
        $files[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
sort($files);

$zoneRules = new ZoneRules();
$ratchet = new Ratchet();
$problems = [];
foreach ($files as $path) {
    $source = new SourceFile($path, (string) file_get_contents($root.'/'.$path));
    $problems = [...$problems, ...$zoneRules->check($source)];
    $ratchet->add($source);
}

$baseline = is_file($baselineFile)
    ? json_decode((string) file_get_contents($baselineFile), true, 512, JSON_THROW_ON_ERROR)
    : null;
if ($update) {
    $json = json_encode($ratchet->tightened($baseline), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($baselineFile, $json."\n");
    echo "Baseline updated: tools/arch-check-baseline.json\n";
    $baseline = $ratchet->tightened($baseline);
}
$problems = [...$problems, ...$ratchet->compare($baseline ?? [])];

if ($problems !== []) {
    fwrite(STDERR, "arch-check FAILED (docs/FIDES-MIGRATION.md):\n  ".implode("\n  ", $problems)."\n");
    exit(1);
}
$counts = array_map('count', $ratchet->findings());
echo 'arch-check OK. Zones follow every rule. Baseline: '
    .implode(', ', array_map(static fn ($rule, $n) => "$n $rule", array_keys($counts), $counts)).".\n";
