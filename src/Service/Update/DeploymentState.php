<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * What the deployment update method knows about code deployed another way
 * (Plesk Git, cPanel Git Version Control, CI/CD, rsync): the last time the
 * post-deployment steps finished, whether files changed since, which commit
 * the tool's Git repository holds, and whether Composer dependencies match
 * composer.lock. Nothing here changes application files.
 */
class DeploymentState
{
    public const RECORD = UpdatePaths::WORK_DIRECTORY.'/deployment.json';
    public const SCHEMA = 1;

    /**
     * Files a deployment copies from the repository and an update must react to:
     * code, templates, migrations, configuration and dependency manifests. Paths
     * the operator owns, generated assets and downloaded dependencies are left out.
     */
    private const FINGERPRINT_PATHS = [
        'composer.json', 'composer.lock', 'importmap.php', 'symfony.lock', 'bin/console', 'public/index.php',
        'src', 'templates', 'translations', 'migrations', 'assets', 'config',
    ];
    private const FINGERPRINT_EXCLUDED = ['assets/vendor/', 'config/reference.php'];
    private const MAX_FINGERPRINT_FILES = 20000;

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * The last finished deployment, or null when none was recorded.
     *
     * @return array{commit: ?string, branch: ?string, fingerprint: string, finished_at: int, update: ?string}|null
     */
    public function record(): ?array
    {
        $raw = @file_get_contents($this->projectDir.'/'.self::RECORD, false, null, 0, 65536);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record) || ($record['schema'] ?? null) !== self::SCHEMA
            || !is_string($record['fingerprint'] ?? null) || !is_int($record['finished_at'] ?? null)) {
            return null;
        }

        return [
            'commit' => self::isCommit($record['commit'] ?? null) ? $record['commit'] : null,
            'branch' => is_string($record['branch'] ?? null) ? $record['branch'] : null,
            'fingerprint' => $record['fingerprint'],
            'finished_at' => $record['finished_at'],
            'update' => is_string($record['update'] ?? null) ? $record['update'] : null,
        ];
    }

    /** Record a finished deployment, replacing the previous record atomically. */
    public function save(?string $commit, ?string $branch, string $fingerprint, string $update): void
    {
        $directory = dirname($this->projectDir.'/'.self::RECORD);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The deployment record could not be saved. Check write access to var/updates.');
        }
        $json = json_encode([
            'schema' => self::SCHEMA,
            'commit' => self::isCommit($commit) ? $commit : null,
            'branch' => $branch,
            'fingerprint' => $fingerprint,
            'finished_at' => time(),
            'update' => $update,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $temporary = $directory.'/.deployment-'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($temporary, $json."\n") === false || !@rename($temporary, $this->projectDir.'/'.self::RECORD)) {
            @unlink($temporary);
            throw new \RuntimeException('The deployment record could not be saved. Check write access to var/updates.');
        }
    }

    /**
     * Whether deployed files differ from the last finished deployment: true when
     * a deployment has not been finished yet.
     */
    public function changedSinceRecord(): bool
    {
        $record = $this->record();

        return $record === null || !hash_equals($record['fingerprint'], $this->fingerprint());
    }

    /** A hash of every deployed file that an update must react to. */
    public function fingerprint(): string
    {
        $files = [];
        foreach (self::FINGERPRINT_PATHS as $path) {
            $absolute = $this->projectDir.'/'.$path;
            if (is_file($absolute)) {
                $files[] = $path;
            } elseif (is_dir($absolute)) {
                $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
                foreach ($items as $item) {
                    if (!$item->isFile()) {
                        continue;
                    }
                    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($this->projectDir) + 1));
                    if (!$this->isExcluded($relative)) {
                        $files[] = $relative;
                    }
                    if (count($files) > self::MAX_FINGERPRINT_FILES) {
                        throw new \RuntimeException('The application directory holds too many files to compare deployments.');
                    }
                }
            }
        }
        sort($files, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($files as $relative) {
            $hash = @hash_file('xxh128', $this->projectDir.'/'.$relative);
            hash_update($context, $relative."\0".($hash === false ? 'unreadable' : $hash)."\n");
        }

        return hash_final($context);
    }

    /**
     * The commit a deployment tool's Git repository holds. A bare repository
     * (Plesk keeps one for each site) is read at refs/heads/$branch; a clone
     * with a working tree (cPanel's repositories) at its checked-out HEAD. Only
     * files are read, so Git need not be installed.
     *
     * @return array{commit: string, branch: ?string}
     */
    public function readRepository(string $path, string $branch): array
    {
        $path = rtrim($path, '/\\');
        if ($path === '' || !is_dir($path)) {
            throw new \RuntimeException('The deployment repository '.$path.' was not found. Check the path, and that this user can read it.');
        }
        $worktree = false;
        if (is_dir($path.'/.git')) {
            $gitDir = $path.'/.git';
            $worktree = true;
        } elseif (is_file($path.'/.git')) {
            $pointer = (string) @file_get_contents($path.'/.git', false, null, 0, 4096);
            if (preg_match('/^gitdir:\s*(.+?)\s*$/m', $pointer, $match) !== 1) {
                throw new \RuntimeException('The .git file in '.$path.' does not point to a Git directory.');
            }
            $gitDir = $this->isAbsolute($match[1]) ? $match[1] : $path.'/'.$match[1];
            $worktree = true;
        } elseif (is_file($path.'/HEAD') && is_dir($path.'/objects')) {
            $gitDir = $path;
        } else {
            throw new \RuntimeException($path.' is not a Git repository. Give the folder your deployment tool keeps the repository in (a bare repository\'s folder usually ends in .git).');
        }
        $commonDir = $gitDir;
        $common = @file_get_contents($gitDir.'/commondir', false, null, 0, 4096);
        if (is_string($common) && trim($common) !== '') {
            $commonDir = $this->isAbsolute(trim($common)) ? trim($common) : $gitDir.'/'.trim($common);
        }

        $head = trim((string) @file_get_contents($gitDir.'/HEAD', false, null, 0, 4096));
        $headBranch = str_starts_with($head, 'ref: refs/heads/') ? substr($head, strlen('ref: refs/heads/')) : null;
        if ($worktree) {
            // A clone with a working tree deploys what is checked out.
            if ($headBranch !== null) {
                $commit = $this->resolveReference($gitDir, $commonDir, 'refs/heads/'.$headBranch);
            } elseif (self::isCommit($head)) {
                $commit = $head;
            } else {
                $commit = null;
            }
            if ($commit === null) {
                throw new \RuntimeException('The checked-out commit of '.$path.' could not be read.');
            }

            return ['commit' => $commit, 'branch' => $headBranch];
        }

        // A bare repository holds every fetched branch; the deployed one is updates_branch.
        foreach (['refs/heads/'.$branch, 'refs/remotes/origin/'.$branch] as $reference) {
            $commit = $this->resolveReference($gitDir, $commonDir, $reference);
            if ($commit !== null) {
                return ['commit' => $commit, 'branch' => $branch];
            }
        }
        throw new \RuntimeException('The deployment repository has no branch '.$branch.'. Set updates_branch to the branch your deployment tool deploys.');
    }

    /**
     * The Git repository the code is deployed from, found without settings:
     * the application directory itself when it is a clone, otherwise the only
     * repository a hosting panel keeps for this site. Null when there is none
     * or more than one.
     */
    public function repository(): ?string
    {
        if (file_exists($this->projectDir.'/.git')) {
            return $this->projectDir;
        }
        $candidates = $this->candidates();

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Git repositories a hosting panel keeps for this site: Plesk's
     * /var/www/vhosts/DOMAIN/git/NAME.git and cPanel's ~/repositories/NAME.
     *
     * @return list<string>
     */
    public function candidates(): array
    {
        $patterns = [];
        $directory = str_replace('\\', '/', $this->projectDir).'/';
        if (preg_match('#^(/var/www/vhosts/[^/]+)/#', $directory, $match) === 1) {
            $patterns[] = $match[1].'/git/*.git';
        }
        if (preg_match('#^(/home\d*/[^/]+)/#', $directory, $match) === 1) {
            $patterns[] = $match[1].'/repositories/*';
        }
        $found = [];
        foreach ($patterns as $pattern) {
            // open_basedir or permissions may hide these folders; that only means none is found.
            foreach (@glob($pattern, GLOB_ONLYDIR) ?: [] as $candidate) {
                if ((@is_file($candidate.'/HEAD') && @is_dir($candidate.'/objects')) || @is_dir($candidate.'/.git')) {
                    $found[] = $candidate;
                }
            }
        }
        sort($found, SORT_STRING);

        return array_values(array_unique($found));
    }

    /**
     * Whether vendor/ differs from what composer.lock requires: missing,
     * different versions, or packages the lock file no longer lists.
     */
    public function dependenciesOutOfDate(bool $includeDev): bool
    {
        if (!is_file($this->projectDir.'/vendor/autoload.php')) {
            return true;
        }
        $lock = $this->json($this->projectDir.'/composer.lock');
        $installed = $this->json($this->projectDir.'/vendor/composer/installed.json');
        if ($lock === null || $installed === null) {
            return $lock !== null;
        }
        $wanted = $this->packages(array_merge(
            is_array($lock['packages'] ?? null) ? $lock['packages'] : [],
            $includeDev && is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [],
        ));
        $have = $this->packages(is_array($installed['packages'] ?? null) ? $installed['packages'] : (array_is_list($installed) ? $installed : []));
        if (!$includeDev) {
            foreach (is_array($installed['dev-package-names'] ?? null) ? $installed['dev-package-names'] : [] as $name) {
                unset($have[$name]);
            }
        }
        ksort($wanted);
        ksort($have);

        return $wanted !== $have;
    }

    public static function isCommit(mixed $commit): bool
    {
        return is_string($commit) && preg_match('/^[0-9a-f]{40}$/D', $commit) === 1;
    }

    private function resolveReference(string $gitDir, string $commonDir, string $reference): ?string
    {
        foreach (array_unique([$gitDir, $commonDir]) as $directory) {
            $loose = trim((string) @file_get_contents($directory.'/'.$reference, false, null, 0, 256));
            if (self::isCommit($loose)) {
                return $loose;
            }
        }
        $packed = @file_get_contents($commonDir.'/packed-refs', false, null, 0, 16777216);
        if (is_string($packed) && preg_match('/^([0-9a-f]{40}) '.preg_quote($reference, '/').'$/m', $packed, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /** @param list<mixed> $packages @return array<string, string> */
    private function packages(array $packages): array
    {
        $versions = [];
        foreach ($packages as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $reference = $package['dist']['reference'] ?? $package['source']['reference'] ?? '';
                $versions[$package['name']] = (string) ($package['version'] ?? '').'@'.(is_string($reference) ? $reference : '');
            }
        }

        return $versions;
    }

    /** @return array<mixed>|null */
    private function json(string $path): ?array
    {
        $raw = @file_get_contents($path, false, null, 0, 33554432);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    private function isExcluded(string $relative): bool
    {
        foreach (self::FINGERPRINT_EXCLUDED as $excluded) {
            if ($relative === $excluded || (str_ends_with($excluded, '/') && str_starts_with($relative, $excluded))) {
                return true;
            }
        }

        return UpdatePaths::isProtected($relative);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
