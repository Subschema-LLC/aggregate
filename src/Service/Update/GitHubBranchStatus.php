<?php

declare(strict_types=1);

namespace App\Service\Update;

use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A GitHub branch's newest commit, and how an installed commit compares with
 * it, from the GitHub API. Answers are cached for an hour, or a minute after
 * an error, separately per repository and access token. Used by
 * ApplicationUpdateService for Git clones and code deployed another way.
 */
final class GitHubBranchStatus
{
    private const CACHE_SECONDS = 3600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly string $githubToken = '',
    ) {
    }

    /**
     * The newest commit of a GitHub branch, cached for an hour (a minute after errors).
     *
     * @return array{sha: ?string, error: ?string, checked_at: int}
     */
    public function head(string $repository, string $branch, bool $refresh): array
    {
        return $this->cache->get(
            $this->cacheKey($repository, 'branch', $branch),
            function (ItemInterface $item) use ($repository, $branch): array {
                $response = $this->request($repository, '/commits/'.rawurlencode($branch));
                $sha = $response['data']['sha'] ?? null;
                $error = $response['error'];
                if ($error === null && !self::isCommit($sha)) {
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
    }

    /**
     * How an installed commit relates to the branch's newest commit, from GitHub,
     * cached like head().
     *
     * @return array{state: string, message: string, behind: ?int}
     */
    public function compare(string $repository, string $current, string $latest, bool $refresh): array
    {
        $comparison = $this->cache->get(
            $this->cacheKey($repository, 'compare', $current.'...'.$latest),
            function (ItemInterface $item) use ($repository, $current, $latest): array {
                $comparison = $this->comparison($repository, $current, $latest);
                $item->expiresAt($this->clock->now()->modify('+'.($comparison['state'] === 'error' ? 60 : self::CACHE_SECONDS).' seconds'));

                return $comparison;
            },
            $refresh ? INF : null,
        );

        return $comparison + ['behind' => null];
    }

    /** @return array{state: string, message: string, behind: ?int} */
    private function comparison(string $repository, string $current, string $latest): array
    {
        $response = $this->request($repository, '/compare/'.$current.'...'.$latest);
        if ($response['status'] === 404) {
            return [
                'state' => 'unknown',
                'message' => 'GitHub cannot compare the installed commit with this branch. The commit may not be published on GitHub (for example a local or fork commit); review its history before updating.',
                'behind' => null,
            ];
        }
        if ($response['error'] !== null) {
            return ['state' => 'error', 'message' => $response['error'], 'behind' => null];
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
            // GitHub's ahead_by counts the branch commits this installation lacks.
            ? ['state' => $state, 'message' => '', 'behind' => $ahead]
            : ['state' => 'error', 'message' => 'GitHub returned invalid comparison metadata. No update status could be determined.', 'behind' => null];
    }

    /** @return array{status: ?int, data: array, error: ?string} */
    private function request(string $repository, string $path): array
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
            $response = $this->httpClient->request('GET', 'https://api.github.com/repos/'.$repository.$path, [
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

    /** Whether a value is a full 40-character Git commit hash. */
    public static function isCommit(mixed $sha): bool
    {
        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/D', $sha) === 1;
    }

    private function cacheKey(string $repository, string $kind, string $value): string
    {
        // Separate credentials so changing repository access does not reuse an old failure.
        return 'aggregate.updates.'.$kind.'.'.hash('sha256', $repository."\0".$value."\0".$this->githubToken);
    }

    /** Drops the cached newest commit, after this installation pulled it. */
    public function forgetHead(string $repository, string $branch): void
    {
        $this->cache->delete($this->cacheKey($repository, 'branch', $branch));
    }
}
