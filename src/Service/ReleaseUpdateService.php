<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Public, read-only discovery of packaged releases. Discovery never verifies or installs a ZIP. */
final class ReleaseUpdateService
{
    private const API_URL = 'https://api.github.com/repos/'.ApplicationUpdateService::REPOSITORY;
    private const PAGE_SIZE = 20;
    private const MAX_PAGES = 3;
    private const MAX_MANIFESTS = 20;

    public function __construct(
        private readonly InstalledRelease $installed,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly UpdateSettings $settings,
        private readonly string $githubToken = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function check(bool $refresh = false): array
    {
        $result = [
            'state' => 'unavailable', 'branch' => null, 'installed_branch' => null,
            'installation_type' => 'release', 'current_commit' => null, 'latest_commit' => null,
            'checked_at' => null, 'message' => '', 'compare_url' => null,
            'current_version' => null, 'latest_version' => null, 'release_url' => null,
            'package_url' => null, 'manifest_url' => null, 'signature_url' => null,
            'signature_verified' => false, 'package_sha256' => null, 'package_size' => null,
            'compatibility_errors' => [], 'search_limited' => false,
        ];
        try {
            $result['branch'] = $this->settings->branch();
            $local = $this->installed->read();
            if ($local === null) {
                $result['message'] = 'This installation has no release.json. Deploy an official release package to enable packaged-release update checks.';

                return $result;
            }
            $result['installed_branch'] = $local['branch'];
            $result['current_commit'] = $local['commit'];
            $result['current_version'] = $local['version'];
            $remote = $this->cache->get(
                'aggregate.release-updates.'.hash('sha256', ApplicationUpdateService::REPOSITORY."\0".$result['branch']."\0".$this->githubToken),
                function (ItemInterface $item): array {
                    try {
                        $remote = $this->releases();
                        $remote['error'] = null;
                    } catch (\RuntimeException $e) {
                        $remote = ['releases' => [], 'search_limited' => false, 'error' => $e->getMessage()];
                    }
                    $remote['checked_at'] = $this->clock->now()->getTimestamp();
                    $item->expiresAt($this->clock->now()->modify('+'.($remote['error'] === null ? 3600 : 60).' seconds'));

                    return $remote;
                },
                $refresh ? INF : null,
            );
            $result['checked_at'] = $remote['checked_at'];
            $result['search_limited'] = $remote['search_limited'];
            if ($remote['error'] !== null) {
                $result['state'] = 'error';
                $result['message'] = $remote['error'];

                return $result;
            }

            $latest = null;
            $incompatible = null;
            foreach ($remote['releases'] as $release) {
                // target_commitish may be a SHA or a branch moved since publishing.
                // Use the explicit build branch in the manifest instead.
                if ($release['metadata']['branch'] !== $result['branch']) {
                    continue;
                }
                $errors = ReleaseMetadata::compatibilityErrors($release['metadata']);
                if ($errors === []) {
                    $latest = $release;
                    break;
                }
                $incompatible ??= $release + ['compatibility_errors' => $errors];
            }
            if ($latest === null && $incompatible === null) {
                $result['state'] = $remote['search_limited'] ? 'unknown' : 'unavailable';
                $result['message'] = 'No packaged stable release was found for the configured updates_branch.';
            } else {
                // Prefer a newer compatible version; if none exists, explain why a
                // newer release cannot run here instead of suggesting a downgrade.
                if ($incompatible !== null && ($latest === null || version_compare($latest['metadata']['version'], $local['version'], '<='))
                    && version_compare($incompatible['metadata']['version'], $local['version'], '>')) {
                    $latest = $incompatible;
                    $result['compatibility_errors'] = $incompatible['compatibility_errors'];
                    $result['state'] = 'incompatible';
                }
                if ($latest === null) {
                    $latest = $incompatible;
                    $result['compatibility_errors'] = $incompatible['compatibility_errors'];
                    $result['state'] = version_compare($local['version'], $incompatible['metadata']['version'], '>')
                        ? ($remote['search_limited'] ? 'unknown' : 'ahead')
                        : 'incompatible';
                }
                $metadata = $latest['metadata'];
                $result['latest_version'] = $metadata['version'];
                $result['latest_commit'] = $metadata['commit'];
                $result['release_url'] = $latest['release_url'];
                $result['package_url'] = $latest['package_url'];
                $result['manifest_url'] = $latest['manifest_url'];
                $result['signature_url'] = $latest['signature_url'];
                $result['package_sha256'] = $metadata['package']['sha256'];
                $result['package_size'] = $metadata['package']['size'];
                $result['compare_url'] = ApplicationUpdateService::REPOSITORY_URL.'/compare/'.$local['commit'].'...'.$metadata['commit'];
                if ($result['state'] !== 'incompatible') {
                    $comparison = version_compare($local['version'], $metadata['version']);
                    $result['state'] = match (true) {
                        $comparison < 0 => 'available',
                        $remote['search_limited'] => 'unknown',
                        $comparison > 0 => 'ahead',
                        $local['commit'] !== $metadata['commit'] => 'error',
                        default => 'up_to_date',
                    };
                }
                $result['message'] = match ($result['state']) {
                    'available' => 'A newer compatible packaged release is available for the configured branch.',
                    'up_to_date' => 'This installation matches the latest compatible packaged release found for the configured branch.',
                    'ahead' => 'The installed version is newer than the packaged releases found. No downgrade is offered.',
                    'incompatible' => 'A packaged release is available but its PHP requirements are not met: '.implode(' ', $result['compatibility_errors']),
                    'error' => 'The installed version and published release have different source commits. Review the original package and release metadata before continuing.',
                    default => 'The bounded release search could not establish whether this installation is current.',
                };
            }
            if ($remote['search_limited']) {
                $result['message'] .= ' The search is limited to the first 60 published release entries and 20 package manifests; review GitHub for older entries.';
            }
            $result['message'] .= ' Release discovery does not verify the package signature or install application files.';
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $result['state'] = 'error';
            $result['message'] = $e->getMessage();
        } catch (\Throwable) {
            $result['state'] = 'error';
            $result['message'] = 'Packaged-release metadata could not be checked or cached. Check network access and application cache permissions, then retry.';
        }

        return $result;
    }

    /** @return array{releases: list<array<string, mixed>>, search_limited: bool} */
    private function releases(): array
    {
        // Bound the complete check as well as each response and request. Nothing
        // returned by GitHub supplies an arbitrary next-page or download URL.
        $deadline = microtime(true) + 20;
        $candidates = [];
        $limited = false;
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $json = $this->request(self::API_URL.'/releases?per_page='.self::PAGE_SIZE.'&page='.$page, 1048576, $deadline);
            try {
                $releases = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException('GitHub returned invalid release-list JSON. Try checking again later.', previous: $e);
            }
            if (!is_array($releases) || !array_is_list($releases) || count($releases) > self::PAGE_SIZE) {
                throw new \RuntimeException('GitHub returned an invalid release list. Try checking again later.');
            }
            foreach ($releases as $release) {
                if (!is_array($release) || ($release['draft'] ?? null) !== false || ($release['prerelease'] ?? null) !== false
                    || !is_string($release['tag_name'] ?? null) || !str_starts_with($release['tag_name'], 'v')
                    || !ReleaseMetadata::isVersion(substr($release['tag_name'], 1))) {
                    continue;
                }
                $candidate = $this->candidate($release);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
            if (count($releases) < self::PAGE_SIZE) {
                break;
            }
            $limited = $page === self::MAX_PAGES;
        }
        usort($candidates, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));
        $limited = $limited || count($candidates) > self::MAX_MANIFESTS;
        $found = [];
        foreach (array_slice($candidates, 0, self::MAX_MANIFESTS) as $candidate) {
            $json = $this->request($candidate['manifest_api_url'], ReleaseMetadata::MAX_BYTES, $deadline, true);
            try {
                $metadata = ReleaseMetadata::parse($json, true);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException('A published package manifest is invalid: '.$e->getMessage(), previous: $e);
            }
            if ($metadata['version'] !== $candidate['version'] || $metadata['package']['size'] !== $candidate['package_size']) {
                throw new \RuntimeException('A published package manifest does not match its GitHub release version or ZIP size. Review the release before updating.');
            }
            $found[] = $candidate + ['metadata' => $metadata];
        }

        return ['releases' => $found, 'search_limited' => $limited];
    }

    /** @param array<string, mixed> $release
     *  @return ?array<string, mixed>
     */
    private function candidate(array $release): ?array
    {
        if (!is_array($release['assets'] ?? null) || !array_is_list($release['assets'])) {
            return null;
        }
        $version = substr($release['tag_name'], 1);
        $names = ['aggregate-'.$version.'.zip', 'aggregate-release.json', 'aggregate-release.json.sig'];
        $assets = [];
        foreach ($release['assets'] as $asset) {
            if (is_array($asset) && is_string($asset['name'] ?? null) && in_array($asset['name'], $names, true)) {
                if (isset($assets[$asset['name']])) {
                    throw new \RuntimeException('A GitHub release has duplicate package assets. Review the published release before updating.');
                }
                $assets[$asset['name']] = $asset;
            }
        }
        if (!isset($assets['aggregate-release.json'])) {
            // Source-only GitHub releases are not installable application packages.
            return null;
        }
        $releaseUrl = ApplicationUpdateService::REPOSITORY_URL.'/releases/tag/'.$release['tag_name'];
        $downloadBase = ApplicationUpdateService::REPOSITORY_URL.'/releases/download/'.$release['tag_name'].'/';
        if (($release['html_url'] ?? null) !== $releaseUrl || count($assets) !== count($names)) {
            throw new \RuntimeException('A packaged GitHub release is missing required assets or has an unexpected release URL.');
        }
        foreach ($assets as $name => $asset) {
            if (!is_int($asset['id'] ?? null) || $asset['id'] <= 0
                || ($asset['url'] ?? null) !== self::API_URL.'/releases/assets/'.$asset['id']
                || ($asset['browser_download_url'] ?? null) !== $downloadBase.$name
                || ($asset['state'] ?? null) !== 'uploaded'
                || !is_int($asset['size'] ?? null) || $asset['size'] <= 0
                || ($name === 'aggregate-release.json' && $asset['size'] > ReleaseMetadata::MAX_BYTES)
                || ($name === 'aggregate-release.json.sig' && $asset['size'] > 128)) {
                throw new \RuntimeException('A packaged GitHub release contains invalid asset metadata or an unexpected download URL.');
            }
        }

        return [
            'version' => $version, 'release_url' => $releaseUrl,
            'package_url' => $downloadBase.$names[0], 'package_size' => $assets[$names[0]]['size'],
            'manifest_url' => $downloadBase.$names[1], 'signature_url' => $downloadBase.$names[2],
            'manifest_api_url' => $assets[$names[1]]['url'],
        ];
    }

    /** Read only bounded data from a constructed GitHub API URL or its validated asset redirect. */
    private function request(string $url, int $maxBytes, float $deadline, bool $asset = false): string
    {
        $headers = [
            'Accept' => $asset ? 'application/octet-stream' : 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'Aggregate-Release-Checker',
        ];
        if ($this->githubToken !== '') {
            $headers['Authorization'] = 'Bearer '.$this->githubToken;
        }
        for ($attempt = 0; $attempt < ($asset ? 2 : 1); ++$attempt) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new \RuntimeException('The packaged-release check exceeded its time limit. Try checking again later.');
            }
            $response = null;
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => $headers, 'timeout' => min(5, $remaining),
                    'max_duration' => min(10, $remaining), 'max_redirects' => 0, 'buffer' => false,
                ]);
                $status = $response->getStatusCode();
                if ($asset && $attempt === 0 && in_array($status, [301, 302, 303, 307, 308], true)) {
                    $locations = $response->getHeaders(false)['location'] ?? [];
                    $response->cancel();
                    if (count($locations) !== 1 || !$this->isAssetRedirect($locations[0])) {
                        throw new \RuntimeException('GitHub returned an unexpected release asset redirect. No redirected metadata was requested.');
                    }
                    $url = $locations[0];
                    // Never forward API credentials to GitHub's public asset CDN.
                    $headers = ['Accept' => 'application/octet-stream', 'User-Agent' => 'Aggregate-Release-Checker'];
                    continue;
                }
                if ($status !== 200) {
                    throw new \RuntimeException(match (true) {
                        $status === 401 => 'GitHub could not authenticate the release check. Review AGGREGATE_GITHUB_TOKEN, or remove it for public repository access.',
                        $status === 403 || $status === 429 => 'GitHub denied or rate-limited the release check. Try again later; AGGREGATE_GITHUB_TOKEN is optional for a higher API limit.',
                        $status === 404 => 'The public GitHub repository or release asset could not be found. Verify that the repository and release are public.',
                        default => 'GitHub could not complete the packaged-release check. Try again later.',
                    });
                }
                $body = '';
                foreach ($this->httpClient->stream($response, min(5, $remaining)) as $chunk) {
                    if ($chunk->isTimeout()) {
                        throw new \RuntimeException('The packaged-release metadata request timed out. Try checking again later.');
                    }
                    $body .= $chunk->getContent();
                    if (strlen($body) > $maxBytes) {
                        throw new \RuntimeException('GitHub release metadata exceeds the permitted response size.');
                    }
                    if (microtime(true) > $deadline) {
                        throw new \RuntimeException('The packaged-release check exceeded its time limit. Try checking again later.');
                    }
                }

                return $body;
            } catch (\Throwable $e) {
                $response?->cancel();
                if ($e instanceof \RuntimeException && !$e instanceof \Symfony\Contracts\HttpClient\Exception\ExceptionInterface) {
                    throw $e;
                }
                throw new \RuntimeException('GitHub release metadata could not be read. Check network access and try again later.', previous: $e);
            }
        }

        throw new \RuntimeException('GitHub returned too many release asset redirects.');
    }

    private function isAssetRedirect(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && in_array($parts['host'] ?? null, ['release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && (!isset($parts['port']) || $parts['port'] === 443)
            && preg_match('~^/github-production-release-asset(?:-[a-z0-9]+)?/~D', $parts['path'] ?? '') === 1;
    }
}
