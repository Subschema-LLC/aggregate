<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Clock\ClockInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Checks the configured upstream branch and permits explicit, fast-forward-only code updates. */
class ApplicationUpdateService
{
    public const REPOSITORY = 'Subschema-LLC/aggregate';
    public const REPOSITORY_URL = 'https://github.com/'.self::REPOSITORY;

    private const API_URL = 'https://api.github.com/repos/'.self::REPOSITORY;
    private const CACHE_SECONDS = 3600;

    public function __construct(
        private readonly string $projectDir,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly string $githubToken = '',
        private readonly ?UpdateSettings $settings = null,
        private readonly ?ReleaseUpdateService $releases = null,
    ) {
    }

    /**
     * @return array<string, mixed> Git or packaged-release update status.
     */
    public function check(bool $refresh = false): array
    {
        $result = [
            'state' => 'unavailable',
            'installation_type' => 'git',
            'branch' => null,
            'installed_branch' => null,
            'current_commit' => null,
            'latest_commit' => null,
            'checked_at' => null,
            'message' => '',
            'compare_url' => null,
        ];

        // Archive installations can live inside an unrelated parent repository.
        // Their own metadata takes precedence unless this root has its own .git.
        if ($this->isReleaseInstallation()) {
            return $this->releases?->check($refresh) ?? array_replace($result, [
                'state' => 'error',
                'installation_type' => 'release',
                'message' => 'Release update checking is unavailable. Rebuild the application cache and verify the release update service configuration.',
            ]);
        }

        try {
            $result['branch'] = $this->configuredBranch();
        } catch (\RuntimeException $e) {
            $result['state'] = 'error';
            $result['message'] = $e->getMessage();

            return $result;
        }
        $branch = $result['branch'];

        try {
            // Local state is never cached: another deployment may have changed HEAD.
            $local = $this->repository();
            $result['installed_branch'] = $local['branch'];
            $result['current_commit'] = $local['commit'];
        } catch (\RuntimeException $e) {
            $result['message'] = $e->getMessage();

            return $result;
        }

        try {
            $remote = $this->cache->get(
                $this->cacheKey('branch', $branch),
                function (ItemInterface $item) use ($branch): array {
                    $response = $this->github('/commits/'.rawurlencode($branch));
                    $sha = $response['data']['sha'] ?? null;
                    $error = $response['error'];
                    if ($error === null && !$this->isCommit($sha)) {
                        $error = 'GitHub returned invalid commit metadata. Try checking for updates again later.';
                    }
                    $item->expiresAt($this->clock->now()->modify('+'.($error === null ? self::CACHE_SECONDS : 60).' seconds'));

                    return [
                        'sha' => $error === null ? $sha : null,
                        'error' => $error,
                        'checked_at' => $this->clock->now()->getTimestamp(),
                    ];
                },
                $refresh ? INF : null,
            );
            $result['checked_at'] = $remote['checked_at'];
            if ($remote['error'] !== null) {
                $result['state'] = 'error';
                $result['message'] = $remote['error'];

                return $result;
            }

            $latest = $remote['sha'];
            $result['latest_commit'] = $latest;
            $result['compare_url'] = self::REPOSITORY_URL.'/compare/'.$local['commit'].'...'.$latest;
            if ($local['commit'] === $latest) {
                $result['state'] = 'up_to_date';
            } else {
                $result['state'] = $this->localComparison($local['commit'], $latest) ?? '';
                if ($result['state'] === '') {
                    $comparison = $this->cache->get(
                        $this->cacheKey('compare', $local['commit'].'...'.$latest),
                        function (ItemInterface $item) use ($local, $latest): array {
                            $comparison = $this->remoteComparison($local['commit'], $latest);
                            $item->expiresAt($this->clock->now()->modify('+'.($comparison['state'] === 'error' ? 60 : self::CACHE_SECONDS).' seconds'));

                            return $comparison;
                        },
                        $refresh ? INF : null,
                    );
                    $result['state'] = $comparison['state'];
                    $result['message'] = $comparison['message'];
                }
            }

            if ($result['message'] === '') {
                $result['message'] = match ($result['state']) {
                    'up_to_date' => 'This checkout matches the latest commit on the configured GitHub branch.',
                    'available' => 'An update is available on the configured GitHub branch.',
                    'ahead' => 'This checkout contains commits ahead of the configured GitHub branch. Review local commits before updating.',
                    'diverged' => 'This checkout and the configured GitHub branch have diverged. Resolve the branch history manually before updating.',
                    default => 'The available history is insufficient to determine whether an update is available.',
                };
            }
        } catch (\Throwable) {
            $result['state'] = 'error';
            $result['message'] = 'Update metadata could not be checked or cached. Check Git availability and application cache permissions, then retry.';
        }

        return $result;
    }

    /**
     * Fetch code only. Dependency installation, database migrations and runtime restarts
     * remain explicit deployment steps performed by the operator.
     *
     * @return array{branch: string, previous_commit: string, current_commit: string, changed: bool}
     */
    public function pull(): array
    {
        if ($this->isReleaseInstallation()) {
            throw new \RuntimeException('This installation uses a release package. Download and verify a newer package and follow DEPLOYMENT.md#updates; Git source pulls are unavailable for packaged installations.');
        }
        $branch = $this->configuredBranch();
        $local = $this->repository();
        $lock = @fopen($local['git_dir'].'/aggregate-update.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('The Git update lock could not be opened. Run the update as the deployment user with write access to this checkout.');
        }

        $locked = false;
        $temporaryRef = null;
        try {
            $locked = flock($lock, LOCK_EX | LOCK_NB);
            if (!$locked) {
                throw new \RuntimeException('Another application update is already running. Wait for it to finish before retrying.');
            }

            $local = $this->repository();
            $this->assertReadyToPull($local, $branch);
            $temporaryRef = 'refs/aggregate-updates/'.bin2hex(random_bytes(16));
            $this->git([
                'fetch', '--no-tags', '--no-prune', '--no-prune-tags', '--no-recurse-submodules', '--no-auto-maintenance',
                '--no-write-fetch-head', '--', self::REPOSITORY_URL.'.git',
                'refs/heads/'.$branch.':'.$temporaryRef,
            ], 'GitHub could not be fetched. Verify network access and the configured updates_branch on GitHub.', 120);

            // A private temporary ref pins this fetch even if another Git client fetches concurrently.
            $latest = trim($this->git(
                ['rev-parse', '--verify', $temporaryRef.'^{commit}'],
                'The fetched GitHub commit could not be resolved. Check the repository and retry.',
            )->getOutput());
            if (!$this->isCommit($latest)) {
                throw new \RuntimeException('Git returned an invalid commit for the update. Check the repository manually.');
            }

            $beforeMerge = $this->repository();
            if ($beforeMerge['branch'] !== $local['branch'] || $beforeMerge['commit'] !== $local['commit']) {
                throw new \RuntimeException('The local branch changed while the update was being fetched. Review the checkout before retrying.');
            }
            $this->assertReadyToPull($beforeMerge, $branch);

            if ($latest !== $local['commit']) {
                if (!$this->isAncestor($local['commit'], $latest)) {
                    throw new \RuntimeException('Git cannot prove a fast-forward update. The branch may be ahead, diverged, or have incomplete shallow history; review its history manually.');
                }
                $this->git([
                    'merge', '--ff-only', '--no-edit', '--no-stat', '--no-autostash',
                    '--no-overwrite-ignore', '--no-verify', $latest,
                ], 'Git could not complete the fast-forward. Check repository permissions, ignored-file conflicts, signature requirements, and Git status before retrying.', 120);
            }

            $updated = $this->repository();
            if ($updated['branch'] !== $local['branch'] || $updated['commit'] !== $latest) {
                throw new \RuntimeException('The checkout does not match the expected update commit. Inspect Git status and branch history before continuing deployment.');
            }

            // Cache failures must not turn a successful code update into a reported failure.
            try {
                $this->cache->delete($this->cacheKey('branch', $branch));
            } catch (\Throwable) {
            }

            return [
                'branch' => $local['branch'],
                'previous_commit' => $local['commit'],
                'current_commit' => $latest,
                'changed' => $latest !== $local['commit'],
            ];
        } finally {
            if ($temporaryRef !== null) {
                try {
                    $this->git(['update-ref', '-d', $temporaryRef], 'The temporary update reference could not be removed.');
                } catch (\RuntimeException) {
                    // An interrupted cleanup leaves only an unused temporary reference.
                }
            }
            if ($locked) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    /** @return array{branch: ?string, commit: string, git_dir: string} */
    private function repository(): array
    {
        $unavailable = 'Updates require Git to be installed and a readable Git checkout at the application directory. Deploy archive or container builds through their normal deployment process.';
        $root = trim($this->git(['rev-parse', '--show-toplevel'], $unavailable)->getOutput());
        if (realpath($root) !== realpath($this->projectDir)) {
            throw new \RuntimeException($unavailable);
        }

        // Inspect configuration before reading objects: promisor clones may otherwise
        // lazily fetch during cat-file or ancestry checks, changing a read-only check.
        $partial = $this->git(
            ['config', '--get', 'extensions.partialClone'],
            $unavailable,
            acceptedExitCodes: [0, 1],
        );
        $promisors = $this->git(
            ['config', '--bool', '--get-regexp', '^remote\..*\.promisor$'],
            $unavailable,
            acceptedExitCodes: [0, 1],
        )->getOutput();
        if ($partial->getExitCode() === 0 || preg_match('/ true$/m', $promisors) === 1) {
            throw new \RuntimeException('Partial or promisor Git clones are not supported for update checks or pulls. Use a normal Git checkout or the existing deployment process.');
        }

        $commit = trim($this->git(['rev-parse', '--verify', 'HEAD^{commit}'], $unavailable)->getOutput());
        if (!$this->isCommit($commit)) {
            throw new \RuntimeException($unavailable);
        }
        $symbolic = $this->git(['symbolic-ref', '--quiet', 'HEAD'], $unavailable, acceptedExitCodes: [0, 1]);
        $branch = $symbolic->getExitCode() === 0 ? trim($symbolic->getOutput()) : null;
        if ($branch !== null) {
            if (!str_starts_with($branch, 'refs/heads/')) {
                throw new \RuntimeException('The checkout does not point to a local deployment branch. Review its Git configuration.');
            }
            $branch = substr($branch, strlen('refs/heads/'));
        }

        return [
            'branch' => $branch,
            'commit' => $commit,
            'git_dir' => trim($this->git(['rev-parse', '--absolute-git-dir'], $unavailable)->getOutput()),
        ];
    }

    /** @param array{branch: ?string, commit: string, git_dir: string} $local */
    private function assertReadyToPull(array $local, string $branch): void
    {
        if ($local['branch'] === null) {
            throw new \RuntimeException('A detached HEAD cannot be updated. Switch to the configured updates_branch before pulling.');
        }
        if ($local['branch'] !== $branch) {
            throw new \RuntimeException('The installed branch differs from the configured updates_branch ('.$branch.'). Switch branches manually or correct config/aggregate.yaml before pulling.');
        }
        foreach (['MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply', 'sequencer', 'BISECT_START'] as $operation) {
            if (file_exists($local['git_dir'].'/'.$operation)) {
                throw new \RuntimeException('A Git merge, rebase, cherry-pick, revert, or bisect is in progress. Finish or cancel it before updating.');
            }
        }
        $status = $this->git(
            ['status', '--porcelain=v1', '-z', '--untracked-files=normal', '--ignore-submodules=none'],
            'Git could not inspect local changes. Check checkout permissions before updating.',
        )->getOutput();
        if ($status !== '') {
            throw new \RuntimeException('The checkout has local changes or untracked files. Commit or move them before pulling an update.');
        }
        $files = $this->git(['ls-files', '-v', '-z'], 'Git could not inspect index flags before updating.')->getOutput();
        foreach (explode("\0", $files) as $file) {
            if ($file !== '' && ($file[0] === 'S' || ctype_lower($file[0]))) {
                throw new \RuntimeException('The checkout uses skip-worktree or assume-unchanged index flags. Clear those flags and review local changes before updating.');
            }
        }
    }

    private function localComparison(string $current, string $latest): ?string
    {
        $known = $this->git(
            ['cat-file', '-e', $latest.'^{commit}'],
            'Git could not inspect the available commit history.',
            acceptedExitCodes: [0, 1, 128],
        );
        if ($known->getExitCode() !== 0) {
            return null;
        }
        if ($this->isAncestor($current, $latest)) {
            return 'available';
        }
        if ($this->isAncestor($latest, $current)) {
            return 'ahead';
        }
        if (trim($this->git(['rev-parse', '--is-shallow-repository'], 'Git could not inspect the available commit history.')->getOutput()) === 'true') {
            return null;
        }

        return 'diverged';
    }

    private function isAncestor(string $ancestor, string $descendant): bool
    {
        return $this->git(
            ['merge-base', '--is-ancestor', $ancestor, $descendant],
            'Git could not verify whether the branch can be fast-forwarded. Inspect its history manually.',
            acceptedExitCodes: [0, 1],
        )->getExitCode() === 0;
    }

    /** @return array{state: string, message: string} */
    private function remoteComparison(string $current, string $latest): array
    {
        $response = $this->github('/compare/'.$current.'...'.$latest);
        if ($response['status'] === 404) {
            return [
                'state' => 'unknown',
                'message' => 'GitHub cannot compare the installed commit with this branch. The local commit may be unpublished; review its history before updating.',
            ];
        }
        if ($response['error'] !== null) {
            return ['state' => 'error', 'message' => $response['error']];
        }

        $data = $response['data'];
        $ahead = $data['ahead_by'] ?? null;
        $behind = $data['behind_by'] ?? null;
        $state = null;
        if (is_int($ahead) && is_int($behind) && $ahead >= 0 && $behind >= 0) {
            $state = match ($data['status'] ?? null) {
                'ahead' => $ahead > 0 && $behind === 0 ? 'available' : null,
                'behind' => $behind > 0 && $ahead === 0 ? 'ahead' : null,
                'diverged' => $ahead > 0 && $behind > 0 ? 'diverged' : null,
                default => null,
            };
        }

        return $state !== null
            ? ['state' => $state, 'message' => '']
            : ['state' => 'error', 'message' => 'GitHub returned invalid comparison metadata. No update status could be determined.'];
    }

    /** @return array{status: ?int, data: array, error: ?string} */
    private function github(string $path): array
    {
        $status = null;
        try {
            $headers = [
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'Aggregate-Update-Checker',
            ];
            if ($this->githubToken !== '') {
                $headers['Authorization'] = 'Bearer '.$this->githubToken;
            }
            $response = $this->httpClient->request('GET', self::API_URL.$path, [
                'headers' => $headers,
                'timeout' => 5,
                'max_duration' => 10,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $error = match (true) {
                $status === 200 => null,
                $status === 401 => 'GitHub could not authenticate the update check. Configure AGGREGATE_GITHUB_TOKEN with repository read access.',
                $status === 403 || $status === 429 => 'GitHub denied or rate-limited the update check. Check AGGREGATE_GITHUB_TOKEN and try again later.',
                $status === 404 => 'The configured updates_branch or repository could not be found on GitHub. Verify updates_branch in config/aggregate.yaml and repository availability.',
                default => 'GitHub could not complete the update check. Try again later.',
            };

            return ['status' => $status, 'data' => $error === null ? $response->toArray(false) : [], 'error' => $error];
        } catch (\Throwable) {
            return ['status' => $status, 'data' => [], 'error' => 'GitHub update metadata could not be read. Check network access and try again later.'];
        }
    }

    private function isCommit(mixed $sha): bool
    {
        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/D', $sha) === 1;
    }

    private function configuredBranch(): string
    {
        try {
            return $this->settings?->branch() ?? UpdateSettings::DEFAULT_BRANCH;
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), previous: $e);
        }
    }

    private function isReleaseInstallation(): bool
    {
        return (file_exists($this->projectDir.'/release.json') || is_link($this->projectDir.'/release.json'))
            && !file_exists($this->projectDir.'/.git') && !is_link($this->projectDir.'/.git');
    }

    private function cacheKey(string $kind, string $value): string
    {
        // Separate credentials so changing repository access does not reuse an old failure.
        return 'aggregate.updates.'.$kind.'.'.hash('sha256', self::REPOSITORY."\0".$value."\0".$this->githubToken);
    }

    /** @param list<string> $arguments @param list<int> $acceptedExitCodes */
    private function git(array $arguments, string $failure, int $timeout = 10, array $acceptedExitCodes = [0]): Process
    {
        try {
            $process = new Process([
                'git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
                '-c', 'submodule.recurse=false', ...$arguments,
            ], $this->projectDir, [
                'GIT_TERMINAL_PROMPT' => '0',
                'GCM_INTERACTIVE' => 'never',
                'GIT_OPTIONAL_LOCKS' => '0',
                'GIT_NO_REPLACE_OBJECTS' => '1',
                'GIT_DIR' => false,
                'GIT_WORK_TREE' => false,
                'GIT_INDEX_FILE' => false,
            ], timeout: $timeout);
            $process->run();
            if (!in_array($process->getExitCode(), $acceptedExitCodes, true)) {
                throw new \RuntimeException();
            }

            return $process;
        } catch (\Throwable) {
            // Git stderr can contain credential-bearing URLs or local configuration.
            throw new \RuntimeException($failure);
        }
    }
}
