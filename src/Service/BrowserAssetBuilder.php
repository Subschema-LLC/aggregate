<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

/** Optional, explicit builds; serving browser scripts never starts Node. */
class BrowserAssetBuilder
{
    public function __construct(private readonly string $projectDir = __DIR__.'/../..')
    {
    }

    public function build(): void
    {
        $directory = $this->projectDir.'/var/browser';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Browser assets could not be built. Check write permissions for var/browser.');
        }
        $lock = @fopen($directory.'/build.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Browser assets could not be built. Check write permissions for var/browser.');
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Another browser asset build is running. Try again after it finishes.');
            }
            try {
                // Fixed argv, no shell or request-supplied paths/options. Never
                // install packages from a web request or disclose process output.
                $process = new Process(['node', 'scripts/build-js.cjs'], $this->projectDir, timeout: 60);
                $process->disableOutput();
                $process->mustRun();
            } catch (\Throwable $error) {
                throw new \RuntimeException('Browser assets could not be built. Install Node.js 18+ and the pinned dependencies with npm ci --ignore-scripts on the build host, check asset write permissions, then run npm run build:js for details. Readable scripts remain available.', previous: $error);
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
