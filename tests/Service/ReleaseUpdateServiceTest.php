<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\FeatureFlags;
use App\Service\ApplicationUpdateService;
use App\Service\InstalledRelease;
use App\Service\ReleaseMetadata;
use App\Service\ReleaseUpdateService;
use App\Service\UpdateSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ReleaseUpdateServiceTest extends TestCase
{
    private const API = 'https://api.github.com/repos/'.ApplicationUpdateService::REPOSITORY;
    private string $directory;
    private MockClock $clock;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-release-check-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->writeInstalled();
        $this->clock = new MockClock('2026-09-14 12:00:00 UTC');
        $this->cache = new ArrayAdapter(clock: $this->clock);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/release.json');
        rmdir($this->directory);
    }

    public function testZipChecksWorkWithoutGitAndCacheOneHourWithExplicitRefresh(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(0, $options['max_redirects']);
            self::assertLessThanOrEqual(10, $options['max_duration']);
            self::assertNotContains('Authorization', array_keys($options['normalized_headers']));

            return str_contains($url, '/releases?') ? $this->json([$this->release()]) : $this->json($this->manifest());
        });
        $service = $this->service($client);
        $before = file_get_contents($this->directory.'/release.json');
        $first = $service->check();

        self::assertSame('available', $first['state'], $first['message']);
        self::assertSame('release', $first['installation_type']);
        self::assertSame('master', $first['branch']);
        self::assertSame('master', $first['installed_branch']);
        self::assertSame('1.0.0', $first['current_version']);
        self::assertSame('2.0.0', $first['latest_version']);
        self::assertFalse($first['signature_verified']);
        self::assertSame('https://github.com/'.ApplicationUpdateService::REPOSITORY.'/releases/download/v2.0.0/aggregate-2.0.0.zip', $first['package_url']);
        self::assertSame(str_repeat('b', 64), $first['package_sha256']);
        self::assertSame(2345, $first['package_size']);
        self::assertSame(2, $client->getRequestsCount());
        $this->clock->sleep(3599);
        self::assertSame($first, $service->check());
        self::assertSame(2, $client->getRequestsCount());
        $this->clock->sleep(2);
        self::assertNotSame($first['checked_at'], $service->check()['checked_at']);
        self::assertSame(4, $client->getRequestsCount());
        $service->check(true);
        self::assertSame(6, $client->getRequestsCount());
        self::assertSame($before, file_get_contents($this->directory.'/release.json'));
        self::assertSame(['release.json'], array_values(array_diff(scandir($this->directory), ['.', '..'])));
    }

    public function testLocalVersionIsRereadEvenWhenRemoteMetadataIsCached(): void
    {
        $client = new MockHttpClient([$this->json([$this->release()]), $this->json($this->manifest())]);
        $service = $this->service($client);
        self::assertSame('available', $service->check()['state']);
        $this->writeInstalled('2.0.0', str_repeat('c', 40));
        self::assertSame('up_to_date', $service->check()['state']);
        $this->writeInstalled('3.0.0');
        $ahead = $service->check();
        self::assertSame('ahead', $ahead['state']);
        self::assertStringContainsString('No downgrade', $ahead['message']);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testChecksManifestBranchInsteadOfTargetCommitishAndSelectsHighestSemanticVersion(): void
    {
        $older = $this->release('1.9.0', 100);
        $newer = $this->release('1.10.0', 200);
        $wrongBranch = $this->release('2.0.0', 300);
        $newer['target_commitish'] = str_repeat('c', 40);
        $client = new MockHttpClient([
            $this->json([$older, $wrongBranch, $newer]),
            $this->json($this->manifest('2.0.0', 'other-branch')),
            $this->json($this->manifest('1.10.0', 'deployment/stable')),
            $this->json($this->manifest('1.9.0', 'deployment/stable')),
        ]);
        $result = $this->service($client, branch: 'deployment/stable')->check();

        self::assertSame('available', $result['state'], $result['message']);
        self::assertSame('deployment/stable', $result['branch']);
        self::assertSame('1.10.0', $result['latest_version']);
        self::assertSame(4, $client->getRequestsCount());
    }

    public function testCalendarReleasesAreOrderedByMonthThenMonthlyIndexAndSupersedeLegacyVersions(): void
    {
        $this->writeInstalled('0.2.0');
        $client = new MockHttpClient([
            $this->json([$this->release('2026.09.09', 100), $this->release('2026.09.10', 200), $this->release('2026.08.12', 300)]),
            $this->json($this->manifest('2026.09.10')),
            $this->json($this->manifest('2026.09.09')),
            $this->json($this->manifest('2026.08.12')),
        ]);

        $result = $this->service($client)->check();

        self::assertSame('available', $result['state'], $result['message']);
        self::assertSame('2026.09.10', $result['latest_version']);
        self::assertStringEndsWith('/releases/download/v2026.09.10/aggregate-2026.09.10.zip', $result['package_url']);
    }

    public function testDraftsPrereleasesNonVersionTagsAndSourceOnlyReleasesAreIgnored(): void
    {
        $draft = $this->release('3.0.0');
        $draft['draft'] = true;
        $prerelease = $this->release('4.0.0');
        $prerelease['prerelease'] = true;
        $tag = $this->release('5.0.0');
        $tag['tag_name'] = 'v5.0.0-beta.1';
        $source = $this->release('6.0.0');
        $source['assets'] = [];
        $client = new MockHttpClient([$this->json([$draft, $prerelease, $tag, $source])]);
        $result = $this->service($client)->check();

        self::assertSame('unavailable', $result['state']);
        self::assertSame(1, $client->getRequestsCount());
        self::assertNull($result['package_url']);
    }

    public function testPaginationFindsPackagesAfterSourceOnlyReleases(): void
    {
        $page = array_fill(0, 20, ['draft' => false, 'prerelease' => false, 'tag_name' => 'v0.1.0', 'assets' => []]);
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls, $page): MockResponse {
            $urls[] = $url;

            return match (count($urls)) {
                1 => $this->json($page),
                2 => $this->json([$this->release()]),
                default => $this->json($this->manifest()),
            };
        });

        self::assertSame('available', $this->service($client)->check()['state']);
        self::assertSame(self::API.'/releases?per_page=20&page=2', $urls[1]);
        self::assertSame(self::API.'/releases/assets/101', $urls[2]);
    }

    public function testPaginationIsBoundedAndCannotClaimUpToDateForAnIncompleteSearch(): void
    {
        $page = array_fill(0, 20, ['draft' => true]);
        $client = new MockHttpClient(fn (): MockResponse => $this->json($page));
        $result = $this->service($client)->check();

        self::assertSame('unknown', $result['state']);
        self::assertTrue($result['search_limited']);
        self::assertSame(3, $client->getRequestsCount());
        self::assertStringContainsString('first 60', $result['message']);
    }

    public function testManifestRequestsAreBoundedAndSortedByVersion(): void
    {
        $releases = [];
        $manifests = [];
        for ($i = 1; $i <= 21; ++$i) {
            $releases[] = $this->release('1.'.$i.'.0', $i * 10);
            $manifests[self::API.'/releases/assets/'.($i * 10 + 1)] = $this->manifest('1.'.$i.'.0');
        }
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use ($releases, $manifests, &$requested): MockResponse {
            $requested[] = $url;

            return $this->json(match ($url) {
                self::API.'/releases?per_page=20&page=1' => array_slice($releases, 0, 20),
                self::API.'/releases?per_page=20&page=2' => array_slice($releases, 20),
                default => $manifests[$url],
            });
        });
        $result = $this->service($client)->check();

        self::assertSame('available', $result['state'], $result['message']);
        self::assertSame('1.21.0', $result['latest_version']);
        self::assertTrue($result['search_limited']);
        self::assertSame(22, $client->getRequestsCount());
        self::assertSame(self::API.'/releases/assets/211', $requested[2]);
        self::assertNotContains(self::API.'/releases/assets/11', $requested);
    }

    public function testSelectsHighestCompatibleReleaseAndExplainsNewerIncompatibleVersions(): void
    {
        $incompatible = $this->manifest('3.0.0');
        $incompatible['requirements']['php'] = '>=99.0';
        $client = new MockHttpClient([
            $this->json([$this->release('2.0.0'), $this->release('3.0.0', 200)]),
            $this->json($incompatible), $this->json($this->manifest()),
        ]);
        $service = $this->service($client);
        $result = $service->check();
        self::assertSame('available', $result['state'], $result['message']);
        self::assertSame('2.0.0', $result['latest_version']);
        self::assertSame([], $result['compatibility_errors']);

        $this->writeInstalled('2.0.0', str_repeat('c', 40));
        $result = $service->check();
        self::assertSame('incompatible', $result['state']);
        self::assertSame('3.0.0', $result['latest_version']);
        self::assertStringContainsString('Requires PHP >=99.0', $result['compatibility_errors'][0]);
    }

    public function testSameVersionWithChangedSourceCommitIsAnError(): void
    {
        $this->writeInstalled('2.0.0');
        $client = new MockHttpClient([$this->json([$this->release()]), $this->json($this->manifest())]);
        $result = $this->service($client)->check();
        self::assertSame('error', $result['state']);
        self::assertStringContainsString('different source commits', $result['message']);
    }

    public function testAnOlderIncompatiblePackageDoesNotSuggestADowngrade(): void
    {
        $this->writeInstalled('3.0.0');
        $manifest = $this->manifest();
        $manifest['requirements']['php'] = '>=99.0';
        $client = new MockHttpClient([$this->json([$this->release()]), $this->json($manifest)]);
        $result = $this->service($client)->check();
        self::assertSame('ahead', $result['state']);
        self::assertStringContainsString('No downgrade', $result['message']);
    }

    public function testMalformedInstalledMetadataNeverRequestsGithub(): void
    {
        $client = new MockHttpClient([]);
        $service = $this->service($client);
        file_put_contents($this->directory.'/release.json', '{broken');
        self::assertSame('error', $service->check()['state']);
        self::assertStringContainsString('Installed release.json is invalid', $service->check()['message']);
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testInstallationWithoutReleaseMetadataIsOfferedTheLatestRelease(): void
    {
        // For example files copied by a deployment tool: no release.json, so the version is unknown.
        @unlink($this->directory.'/release.json');
        $client = new MockHttpClient([
            $this->json([$this->release('2026.09.02', 100)]),
            $this->json($this->manifest('2026.09.02')),
        ]);

        $result = $this->service($client)->check();

        self::assertSame('available', $result['state'], $result['message']);
        self::assertTrue($result['adopting']);
        self::assertNull($result['current_version']);
        self::assertNull($result['compare_url']);
        self::assertSame('2026.09.02', $result['latest_version']);
        self::assertStringContainsString('has no release.json', $result['message']);
    }

    public function testConfiguredRepositoryIsQueriedAndItsManifestsMustNameIt(): void
    {
        $this->writeInstalled('2026.09.01');
        $urls = [];
        $release = $this->release('2026.09.02', 100);
        $release = json_decode(str_replace('Subschema-LLC\\/aggregate', 'example-org\\/aggregate-fork', json_encode($release, JSON_THROW_ON_ERROR)), true);
        $manifest = $this->manifest('2026.09.02');
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls, $release, &$manifest): MockResponse {
            $urls[] = $url;

            return str_contains($url, '/releases?') ? $this->json([$release]) : $this->json($manifest);
        });
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn(['updates_branch' => 'master', 'updates_repository' => 'example-org/aggregate-fork']);
        $service = new ReleaseUpdateService(new InstalledRelease($this->directory), $client, $this->cache, $this->clock, new UpdateSettings($config), new FeatureFlags($config));

        $result = $service->check();
        self::assertStringStartsWith('https://api.github.com/repos/example-org/aggregate-fork/releases', $urls[0]);
        self::assertSame('error', $result['state'], 'A manifest naming another repository is refused.');
        self::assertStringContainsString('example-org/aggregate-fork', $result['message']);

        $manifest['repository'] = 'example-org/aggregate-fork';
        $result = $service->check(true);
        self::assertSame('available', $result['state'], $result['message']);
        self::assertStringStartsWith('https://github.com/example-org/aggregate-fork/releases/download/', $result['package_url']);
    }

    public function testInvalidBranchAndMalformedConfigAreErrorsBeforeNetworkRequests(): void
    {
        $client = new MockHttpClient([]);
        self::assertSame('error', $this->service($client, branch: '../master')->check()['state']);
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('assertHealthy')->willThrowException(new \RuntimeException('Aggregate configuration YAML is invalid.'));
        $service = new ReleaseUpdateService(new InstalledRelease($this->directory), $client, $this->cache, $this->clock, new UpdateSettings($config), new FeatureFlags($config));
        self::assertSame('disabled', $service->check()['state']);
        self::assertStringContainsString('configuration is invalid', $service->check()['message']);
        self::assertSame(0, $client->getRequestsCount());
    }

    #[DataProvider('httpErrors')]
    public function testApiFailuresAreBrieflyCachedAndNeverExposeResponseBodies(int $status, string $message): void
    {
        $client = new MockHttpClient(fn (): MockResponse => new MockResponse('secret upstream error', ['http_code' => $status]));
        $service = $this->service($client);
        $result = $service->check();
        self::assertSame('error', $result['state']);
        self::assertStringContainsString($message, $result['message']);
        self::assertStringNotContainsString('secret upstream error', $result['message']);
        self::assertSame($result, $service->check());
        self::assertSame(1, $client->getRequestsCount());
        $this->clock->sleep(61);
        $service->check();
        self::assertSame(2, $client->getRequestsCount());
    }

    public static function httpErrors(): iterable
    {
        yield 'unauthorized' => [401, 'authenticate'];
        yield 'denied' => [403, 'rate-limited'];
        yield 'not public' => [404, 'public'];
        yield 'rate limit' => [429, 'rate-limited'];
        yield 'server error' => [500, 'could not complete'];
        yield 'unexpected list redirect' => [302, 'could not complete'];
    }

    public function testNetworkFailuresAreSanitized(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('private bearer token');
        });
        $result = $this->service($client)->check();
        self::assertSame('error', $result['state']);
        self::assertStringContainsString('Check network access', $result['message']);
        self::assertStringNotContainsString('private bearer token', $result['message']);
    }

    #[DataProvider('invalidReleaseLists')]
    public function testRejectsMalformedAndOversizedReleaseLists(string $body): void
    {
        $client = new MockHttpClient([new MockResponse($body)]);
        self::assertSame('error', $this->service($client)->check()['state']);
        self::assertSame(1, $client->getRequestsCount());
    }

    public static function invalidReleaseLists(): iterable
    {
        yield 'invalid JSON' => ['{broken'];
        yield 'object' => ['{"message":"bad"}'];
        yield 'size' => [str_repeat(' ', 1048577)];
        yield 'too many entries' => [json_encode(array_fill(0, 21, []), JSON_THROW_ON_ERROR)];
    }

    #[DataProvider('invalidManifests')]
    public function testRejectsManifestTamperingAndReleaseIdentityMismatches(array $changes): void
    {
        $manifest = array_replace_recursive($this->manifest(), $changes);
        $client = new MockHttpClient([$this->json([$this->release()]), $this->json($manifest)]);
        $result = $this->service($client)->check();
        self::assertSame('error', $result['state']);
        self::assertFalse($result['signature_verified']);
        self::assertNull($result['package_url']);
    }

    public static function invalidManifests(): iterable
    {
        yield 'wrong branch format' => [['branch' => '../master']];
        yield 'wrong repository' => [['repository' => 'attacker/repo']];
        yield 'schema' => [['schema' => 999]];
        yield 'wrong version' => [['version' => '3.0.0', 'package' => ['filename' => 'aggregate-3.0.0.zip']]];
        yield 'wrong size' => [['package' => ['size' => 12345]]];
        yield 'wrong hash' => [['package' => ['sha256' => 'bad']]];
    }

    public function testRejectsOversizedManifestResponseEvenWhenDeclaredAssetSizeIsSmall(): void
    {
        $client = new MockHttpClient([$this->json([$this->release()]), new MockResponse(str_repeat(' ', ReleaseMetadata::MAX_BYTES + 1))]);
        $result = $this->service($client)->check();
        self::assertSame('error', $result['state']);
        self::assertStringContainsString('response size', $result['message']);
    }

    #[DataProvider('invalidAssets')]
    public function testRejectsUnsafeAssetUrlsAndIncompletePackages(string $field, mixed $value): void
    {
        $release = $this->release();
        if ($field === 'missing_signature') {
            array_pop($release['assets']);
        } elseif ($field === 'html_url') {
            $release['html_url'] = $value;
        } else {
            $release['assets'][1][$field] = $value;
        }
        $client = new MockHttpClient([$this->json([$release])]);
        $result = $this->service($client)->check();
        self::assertSame('error', $result['state']);
        self::assertSame(1, $client->getRequestsCount());
    }

    public static function invalidAssets(): iterable
    {
        yield 'API hostname' => ['url', 'https://attacker.invalid/asset'];
        yield 'API asset mismatch' => ['url', self::API.'/releases/assets/999'];
        yield 'download URL' => ['browser_download_url', 'https://github.com/attacker/aggregate/releases/download/v2.0.0/aggregate-release.json'];
        yield 'missing asset' => ['missing_signature', null];
        yield 'release URL' => ['html_url', 'https://attacker.invalid/release'];
        yield 'string ID' => ['id', '101'];
        yield 'incomplete upload' => ['state', 'new'];
        yield 'oversized manifest' => ['size', ReleaseMetadata::MAX_BYTES + 1];
    }

    public function testValidatedCdnRedirectNeverReceivesApiCredentials(): void
    {
        $cdn = 'https://release-assets.githubusercontent.com/github-production-release-asset/123/package?download=token';
        $requests = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($cdn, &$requests): MockResponse {
            ++$requests;
            self::assertSame(0, $options['max_redirects']);
            if ($requests <= 2) {
                self::assertContains('Authorization: Bearer secret-test-token', $options['headers']);
            } else {
                self::assertSame($cdn, $url);
                self::assertArrayNotHasKey('authorization', $options['normalized_headers']);
            }

            return match ($requests) {
                1 => $this->json([$this->release()]),
                2 => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location: '.$cdn]]),
                default => $this->json($this->manifest()),
            };
        });
        $result = $this->service($client, token: 'secret-test-token')->check();
        self::assertSame('available', $result['state'], $result['message']);
        self::assertSame(3, $requests);
        self::assertFalse($result['signature_verified']);
    }

    #[DataProvider('unsafeRedirects')]
    public function testRefusesUnsafeAssetRedirectsWithoutRequestingThem(string $location): void
    {
        $client = new MockHttpClient([
            $this->json([$this->release()]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location: '.$location]]),
        ]);
        $result = $this->service($client, token: 'secret')->check();
        self::assertSame('error', $result['state']);
        self::assertStringContainsString('unexpected release asset redirect', $result['message']);
        self::assertSame(2, $client->getRequestsCount());
    }

    public static function unsafeRedirects(): iterable
    {
        yield 'arbitrary host' => ['https://attacker.invalid/github-production-release-asset/x'];
        yield 'plain HTTP' => ['http://release-assets.githubusercontent.com/github-production-release-asset/x'];
        yield 'userinfo' => ['https://attacker@release-assets.githubusercontent.com/github-production-release-asset/x'];
        yield 'port' => ['https://release-assets.githubusercontent.com:444/github-production-release-asset/x'];
        yield 'unexpected path' => ['https://release-assets.githubusercontent.com/api/private'];
        yield 'fragment' => ['https://release-assets.githubusercontent.com/github-production-release-asset/x#unexpected'];
    }

    public function testCredentialsAndConfiguredBranchSeparateCachedReleaseChecks(): void
    {
        $client = new MockHttpClient(fn (): MockResponse => $this->json([]));
        $this->service($client)->check();
        $this->service($client, token: 'new-token')->check();
        $this->service($client, branch: 'stable')->check();
        self::assertSame(3, $client->getRequestsCount());
    }

    private function service(MockHttpClient $client, string $token = '', string $branch = 'master'): ReleaseUpdateService
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn(['updates_branch' => $branch]);

        return new ReleaseUpdateService(new InstalledRelease($this->directory), $client, $this->cache, $this->clock, new UpdateSettings($config), new FeatureFlags($config), $token);
    }

    private function writeInstalled(string $version = '1.0.0', ?string $commit = null): void
    {
        $metadata = $this->manifest($version);
        unset($metadata['package']);
        $metadata['commit'] = $commit ?? str_repeat('a', 40);
        file_put_contents($this->directory.'/release.json', json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    private function manifest(string $version = '2.0.0', string $branch = 'master'): array
    {
        return [
            'schema' => 1, 'version' => $version, 'repository' => ApplicationUpdateService::REPOSITORY,
            'branch' => $branch, 'commit' => str_repeat('c', 40), 'built_at' => '2026-09-14T12:00:00Z',
            'requirements' => ['php' => '>=8.2', 'extensions' => ['json']],
            'package' => ['filename' => 'aggregate-'.$version.'.zip', 'sha256' => str_repeat('b', 64), 'size' => 2345],
        ];
    }

    private function release(string $version = '2.0.0', int $assetId = 100): array
    {
        $download = ApplicationUpdateService::REPOSITORY_URL.'/releases/download/v'.$version.'/';
        $assets = [];
        foreach (['aggregate-'.$version.'.zip' => 2345, 'aggregate-release.json' => 600, 'aggregate-release.json.sig' => 89] as $name => $size) {
            $assets[] = [
                'id' => $assetId, 'name' => $name, 'state' => 'uploaded', 'size' => $size,
                'url' => self::API.'/releases/assets/'.$assetId++, 'browser_download_url' => $download.$name,
            ];
        }

        return [
            'draft' => false, 'prerelease' => false, 'tag_name' => 'v'.$version, 'target_commitish' => 'master',
            'html_url' => ApplicationUpdateService::REPOSITORY_URL.'/releases/tag/v'.$version, 'assets' => $assets,
        ];
    }

    private function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
