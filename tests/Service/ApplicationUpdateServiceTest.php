<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\ApplicationUpdateService;
use App\Service\InstalledRelease;
use App\Service\ReleaseUpdateService;
use App\Service\UpdateSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

final class ApplicationUpdateServiceTest extends TestCase
{
    private string $directory;
    private string $source;
    private string $project;
    private string $initial;
    private MockClock $clock;
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-updates-'.bin2hex(random_bytes(8));
        $this->source = $this->directory.'/source';
        $this->project = $this->directory.'/installation';
        self::assertTrue(mkdir($this->source, 0700, true));
        $this->git(['init', '--initial-branch=deployment/stable'], $this->source);
        file_put_contents($this->source.'/.gitignore', "config/aggregate.yaml\n.env\nvar/\n");
        $this->initial = $this->commit($this->source, 'application.txt', 'initial version');
        $this->git(['clone', '--quiet', $this->source, $this->project], $this->directory);
        // Production keeps the URL fixed. Only this disposable clone rewrites it to
        // a local fixture so a test can never pull from the real GitHub repository.
        $this->git(['config', 'url.'.$this->source.'.insteadOf', ApplicationUpdateService::REPOSITORY_URL.'.git']);
        $this->clock = new MockClock('2026-09-14 12:00:00 UTC');
        $this->cache = new ArrayAdapter(clock: $this->clock);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public function testChecksAreCachedForOneHourAndRefreshCanBypassTheCache(): void
    {
        $client = new MockHttpClient(fn (): MockResponse => $this->json(['sha' => $this->initial]));
        $service = $this->service($client);
        $refs = $this->git(['show-ref']);
        $first = $service->check();

        self::assertSame('up_to_date', $first['state']);
        self::assertSame('deployment/stable', $first['branch']);
        self::assertSame('deployment/stable', $first['installed_branch']);
        self::assertSame($this->initial, $first['current_commit']);
        self::assertSame($this->initial, $first['latest_commit']);
        self::assertSame($this->clock->now()->getTimestamp(), $first['checked_at']);
        $this->clock->sleep(3599);
        self::assertSame($first, $service->check());
        self::assertSame(1, $client->getRequestsCount());
        $this->clock->sleep(2);
        self::assertNotSame($first['checked_at'], $service->check()['checked_at']);
        self::assertSame(2, $client->getRequestsCount());
        $service->check(true);
        self::assertSame(3, $client->getRequestsCount());
        self::assertSame($refs, $this->git(['show-ref']));
        self::assertFileDoesNotExist($this->project.'/.git/FETCH_HEAD');
    }

    public function testCachedRemoteMetadataStillUsesTheCurrentLocalCommit(): void
    {
        $client = new MockHttpClient([$this->json(['sha' => $this->initial])]);
        $service = $this->service($client);
        self::assertSame('up_to_date', $service->check()['state']);
        $local = $this->commit($this->project, 'local.txt', 'unpublished local commit');

        $result = $service->check();

        self::assertSame('ahead', $result['state']);
        self::assertSame($local, $result['current_commit']);
        self::assertSame(1, $client->getRequestsCount());
    }

    #[DataProvider('comparisonStates')]
    public function testGitHubComparisonClassifiesCommitsAbsentLocally(string $status, int $ahead, int $behind, string $expected): void
    {
        $latest = str_repeat('a', 40);
        $client = new MockHttpClient([
            $this->json(['sha' => $latest]),
            $this->json(['status' => $status, 'ahead_by' => $ahead, 'behind_by' => $behind]),
        ]);

        $result = $this->service($client)->check();

        self::assertSame($expected, $result['state']);
        self::assertSame(ApplicationUpdateService::REPOSITORY_URL.'/compare/'.$this->initial.'...'.$latest, $result['compare_url']);
        self::assertSame(2, $client->getRequestsCount());
    }

    public static function comparisonStates(): iterable
    {
        yield 'GitHub branch is ahead' => ['ahead', 2, 0, 'available'];
        yield 'local installation is ahead' => ['behind', 0, 2, 'ahead'];
        yield 'both branches contain commits' => ['diverged', 2, 3, 'diverged'];
        yield 'inconsistent identical response' => ['identical', 0, 0, 'error'];
        yield 'inconsistent ahead response' => ['ahead', 0, 0, 'error'];
    }

    public function testUnpublishedCommitComparisonIsUnknownWithoutFetching(): void
    {
        $client = new MockHttpClient([
            $this->json(['sha' => str_repeat('b', 40)]),
            $this->json(['message' => 'Not Found'], 404),
        ]);

        $result = $this->service($client)->check();

        self::assertSame('unknown', $result['state']);
        self::assertStringContainsString('unpublished', $result['message']);
        self::assertFileDoesNotExist($this->project.'/.git/FETCH_HEAD');
    }

    public function testAvailableLocalHistoryAvoidsTheGitHubComparisonEndpoint(): void
    {
        $latest = $this->commit($this->source, 'application.txt', 'new version');
        $this->git(['fetch', '--quiet', $this->source, 'deployment/stable']);
        $client = new MockHttpClient([$this->json(['sha' => $latest])]);

        self::assertSame('available', $this->service($client)->check()['state']);
        self::assertSame(1, $client->getRequestsCount());
        self::assertSame($this->initial, $this->git(['rev-parse', 'HEAD']));
    }

    public function testDivergedLocalHistoryDoesNotRequirePublishingLocalCommits(): void
    {
        $latest = $this->commit($this->source, 'remote.txt', 'remote addition');
        $this->git(['fetch', '--quiet', $this->source, 'deployment/stable']);
        $this->commit($this->project, 'local.txt', 'local addition');
        $client = new MockHttpClient([$this->json(['sha' => $latest])]);

        self::assertSame('diverged', $this->service($client)->check()['state']);
        self::assertSame(1, $client->getRequestsCount());
    }

    #[DataProvider('failedMetadata')]
    public function testHttpAndMalformedMetadataFailuresNeverClaimAnUpdate(int $status, string $body): void
    {
        $client = new MockHttpClient([new MockResponse($body, ['http_code' => $status])]);

        $result = $this->service($client)->check();

        self::assertSame('error', $result['state']);
        self::assertNull($result['latest_commit']);
        self::assertNotSame('', $result['message']);
        self::assertStringNotContainsString('server-secret', $result['message']);
    }

    public static function failedMetadata(): iterable
    {
        foreach ([401, 403, 404, 429, 500, 301] as $status) {
            yield (string) $status => [$status, '{"message":"server-secret"}'];
        }
        yield 'invalid JSON' => [200, 'server-secret: malformed'];
        yield 'missing SHA' => [200, '{}'];
        yield 'invalid SHA' => [200, '{"sha":"server-secret"}'];
        yield 'array SHA' => [200, '{"sha":[]}'];
    }

    public function testTransportFailuresAreSanitizedAndRetriedAfterOneMinute(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new TransportException('Network error with server-secret');
        });
        $result = $this->service($client)->check();

        self::assertSame('error', $result['state']);
        self::assertStringNotContainsString('server-secret', $result['message']);
        $client->setResponseFactory([$this->json(['sha' => $this->initial])]);
        self::assertSame('error', $this->service($client)->check()['state']);
        $this->clock->sleep(61);
        self::assertSame('up_to_date', $this->service($client)->check()['state']);
    }

    public function testTokenGoesOnlyToTheCanonicalApiAndSeparatesCachedAuthenticationResults(): void
    {
        $anonymous = new MockHttpClient([$this->json([], 404)]);
        self::assertSame('error', $this->service($anonymous)->check()['state']);
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://api.github.com/repos/Subschema-LLC/aggregate/commits/deployment%2Fstable', $url);
            self::assertContains('Authorization: Bearer secret-test-token', $options['headers']);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(10.0, (float) $options['max_duration']);

            return $this->json(['sha' => $this->initial]);
        });

        $result = $this->service($client, 'secret-test-token')->check();

        self::assertSame('up_to_date', $result['state']);
        self::assertStringNotContainsString('secret-test-token', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testDetachedCheckoutCanCompareAgainstConfiguredBranchButCannotBePulled(): void
    {
        $this->git(['checkout', '--detach', '--quiet']);
        $client = new MockHttpClient([$this->json(['sha' => $this->initial])]);
        $service = $this->service($client);
        $result = $service->check();

        self::assertSame('up_to_date', $result['state']);
        self::assertSame('deployment/stable', $result['branch']);
        self::assertNull($result['installed_branch']);
        self::assertSame($this->initial, $result['current_commit']);
        self::assertSame(1, $client->getRequestsCount());
        $this->assertPullFails($service, 'detached HEAD');
    }

    public function testDefaultMasterIsCheckedWithoutChangingAnInstalledDifferentBranch(): void
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('https://api.github.com/repos/Subschema-LLC/aggregate/commits/master', $url);

            return $this->json(['sha' => $this->initial]);
        });
        $service = new ApplicationUpdateService($this->project, $client, $this->cache, $this->clock);
        $result = $service->check();

        self::assertSame('up_to_date', $result['state']);
        self::assertSame('master', $result['branch']);
        self::assertSame('deployment/stable', $result['installed_branch']);
        $this->assertPullFails($service, 'installed branch differs');
        self::assertSame('deployment/stable', $this->git(['branch', '--show-current']));
        self::assertSame($this->initial, $this->git(['rev-parse', 'HEAD']));
        self::assertSame('', $this->git(['for-each-ref', '--format=%(refname)', 'refs/aggregate-updates/']));
    }

    public function testConfiguredBranchSeparatesCachedResults(): void
    {
        $client = new MockHttpClient([
            $this->json([], 404),
            $this->json(['sha' => $this->initial]),
        ]);

        self::assertSame('error', $this->service($client, branch: 'missing')->check()['state']);
        self::assertSame('up_to_date', $this->service($client)->check()['state']);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testInvalidExplicitBranchFailsBeforeCheckingOrPulling(): void
    {
        $client = new MockHttpClient([]);
        $service = $this->service($client, branch: null);
        $result = $service->check();

        self::assertSame('error', $result['state']);
        self::assertStringContainsString('updates_branch', $result['message']);
        self::assertNull($result['current_commit']);
        self::assertSame(0, $client->getRequestsCount());
        $this->assertPullFails($service, 'updates_branch');
        self::assertFileDoesNotExist($this->project.'/.git/aggregate-update.lock');
    }

    public function testNonGitInstallationAndDirectoryInsideAnotherRepositoryAreUnavailable(): void
    {
        $client = new MockHttpClient([]);
        $service = new ApplicationUpdateService($this->directory, $client, $this->cache, $this->clock);
        self::assertSame('unavailable', $service->check()['state']);
        mkdir($this->project.'/subdirectory');
        $nested = new ApplicationUpdateService($this->project.'/subdirectory', $client, $this->cache, $this->clock);
        self::assertSame('unavailable', $nested->check()['state']);
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testArchiveInsideAnotherCheckoutDelegatesToReleaseChecksWithoutUsingParentGit(): void
    {
        $archive = $this->project.'/archive';
        mkdir($archive);
        file_put_contents($archive.'/release.json', json_encode([
            'schema' => 1,
            'version' => '1.0.0',
            'repository' => ApplicationUpdateService::REPOSITORY,
            'branch' => 'master',
            'commit' => str_repeat('c', 40),
            'built_at' => '2026-09-14T12:00:00Z',
            'requirements' => ['php' => '>=8.2', 'extensions' => []],
        ], JSON_THROW_ON_ERROR));
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            self::assertSame('https://api.github.com/repos/Subschema-LLC/aggregate/releases?per_page=20&page=1', $url);

            return $this->json([]);
        });
        $service = $this->archiveService($archive, $client);

        $result = $service->check();

        self::assertSame('release', $result['installation_type']);
        self::assertSame('1.0.0', $result['current_version']);
        self::assertSame('master', $result['branch']);
        self::assertSame(str_repeat('c', 40), $result['current_commit']);
        self::assertSame(1, $client->getRequestsCount());
        $service->check(true);
        self::assertSame(2, $client->getRequestsCount());
        $this->assertPullFails($service, 'installation uses a release package');
        self::assertSame($this->initial, $this->git(['rev-parse', 'HEAD']));
        self::assertFileDoesNotExist($this->project.'/.git/aggregate-update.lock');
    }

    public function testMalformedArchiveMetadataIsReportedByReleaseCheckerWithoutGitFallback(): void
    {
        $archive = $this->project.'/archive';
        mkdir($archive);
        file_put_contents($archive.'/release.json', '{invalid-json');
        $client = new MockHttpClient([]);

        $result = $this->archiveService($archive, $client)->check();

        self::assertSame('release', $result['installation_type']);
        self::assertSame('error', $result['state']);
        self::assertStringContainsString('release.json is invalid', $result['message']);
        self::assertNull($result['current_commit']);
        self::assertSame(0, $client->getRequestsCount());
    }

    #[DataProvider('gitRootKinds')]
    public function testRootGitMetadataTakesPrecedenceOverStaleReleaseMetadata(bool $linkedWorktree): void
    {
        $checkout = $this->project;
        if ($linkedWorktree) {
            $checkout = $this->directory.'/worktree';
            $this->git(['worktree', 'add', '--quiet', '--detach', $checkout], $this->source);
            self::assertFileExists($checkout.'/.git');
        } else {
            self::assertDirectoryExists($checkout.'/.git');
        }
        file_put_contents($checkout.'/release.json', '{stale-invalid-release');
        $client = new MockHttpClient([$this->json(['sha' => $this->initial])]);

        $result = $this->service($client, project: $checkout)->check();

        self::assertSame('git', $result['installation_type']);
        self::assertSame('up_to_date', $result['state']);
        self::assertSame($this->initial, $result['current_commit']);
        self::assertSame(1, $client->getRequestsCount());
    }

    public static function gitRootKinds(): iterable
    {
        yield 'own .git directory' => [false];
        yield 'own .git file for a linked worktree' => [true];
    }

    public function testPromisorClonesAreRefusedBeforeObjectInspectionCanFetch(): void
    {
        $this->git(['config', 'remote.origin.promisor', 'true']);
        $client = new MockHttpClient([]);
        $service = $this->service($client);

        $result = $service->check();

        self::assertSame('unavailable', $result['state']);
        self::assertNull($result['current_commit']);
        self::assertStringContainsString('promisor', $result['message']);
        self::assertSame(0, $client->getRequestsCount());
        $this->assertPullFails($service, 'promisor');
        self::assertFileDoesNotExist($this->project.'/.git/FETCH_HEAD');
    }

    public function testPullUsesCanonicalRepositoryAndConfiguredBranchPreservingLocalConfigurationAndTags(): void
    {
        $latest = $this->commit($this->source, 'application.txt', 'updated application');
        mkdir($this->project.'/config');
        file_put_contents($this->project.'/config/aggregate.yaml', 'local-secret');
        file_put_contents($this->project.'/.env', 'local-environment');
        $this->git(['remote', 'set-url', 'origin', $this->directory.'/wrong-remote']);
        $this->git(['tag', 'local-deployment']);
        $this->git(['config', 'fetch.prune', 'true']);
        $this->git(['config', 'fetch.pruneTags', 'true']);
        $client = new MockHttpClient([]);

        $result = $this->service($client)->pull();

        self::assertSame([
            'branch' => 'deployment/stable',
            'previous_commit' => $this->initial,
            'current_commit' => $latest,
            'changed' => true,
        ], $result);
        self::assertSame($latest, $this->git(['rev-parse', 'HEAD']));
        self::assertSame('updated application', file_get_contents($this->project.'/application.txt'));
        self::assertSame('local-secret', file_get_contents($this->project.'/config/aggregate.yaml'));
        self::assertSame('local-environment', file_get_contents($this->project.'/.env'));
        self::assertSame($this->initial, $this->git(['rev-parse', 'refs/tags/local-deployment']));
        self::assertSame($this->directory.'/wrong-remote', $this->git(['remote', 'get-url', 'origin']));
        self::assertSame('', $this->git(['for-each-ref', '--format=%(refname)', 'refs/aggregate-updates/']));
        self::assertSame('', $this->git(['status', '--porcelain']));
        self::assertSame(0, $client->getRequestsCount());
    }

    public function testPullMatchingCommitIsASuccessfulNoOp(): void
    {
        $result = $this->service()->pull();

        self::assertFalse($result['changed']);
        self::assertSame($this->initial, $result['previous_commit']);
        self::assertSame($this->initial, $result['current_commit']);
    }

    #[DataProvider('dirtyWorkingTrees')]
    public function testPullRefusesTrackedStagedAndUntrackedChanges(string $kind): void
    {
        $this->commit($this->source, 'application.txt', 'remote change');
        if ($kind === 'untracked') {
            file_put_contents($this->project.'/operator-notes.txt', 'keep me');
        } else {
            file_put_contents($this->project.'/application.txt', 'keep local changes');
            if ($kind === 'staged') {
                $this->git(['add', 'application.txt']);
            }
        }
        $before = $this->git(['status', '--porcelain']);

        $this->assertPullFails($this->service(), 'local changes or untracked files');

        self::assertSame($before, $this->git(['status', '--porcelain']));
        self::assertSame($this->initial, $this->git(['rev-parse', 'HEAD']));
        self::assertSame('', $this->git(['for-each-ref', '--format=%(refname)', 'refs/aggregate-updates/']));
    }

    public static function dirtyWorkingTrees(): iterable
    {
        yield 'unstaged' => ['unstaged'];
        yield 'staged' => ['staged'];
        yield 'untracked' => ['untracked'];
    }

    public function testPullNeverOverwritesIgnoredConfigurationThatBecomesTrackedUpstream(): void
    {
        mkdir($this->project.'/config');
        file_put_contents($this->project.'/config/aggregate.yaml', 'local-secret');
        mkdir($this->source.'/config');
        file_put_contents($this->source.'/config/aggregate.yaml', 'upstream configuration');
        $this->git(['add', '--force', 'config/aggregate.yaml'], $this->source);
        $this->commit($this->source, 'application.txt', 'remote change');

        $this->assertPullFails($this->service(), 'ignored-file conflicts');

        self::assertSame('local-secret', file_get_contents($this->project.'/config/aggregate.yaml'));
        self::assertSame($this->initial, $this->git(['rev-parse', 'HEAD']));
        self::assertSame('initial version', file_get_contents($this->project.'/application.txt'));
    }

    #[DataProvider('nonFastForwardHistories')]
    public function testPullRejectsAheadAndDivergedBranches(bool $diverged): void
    {
        if ($diverged) {
            $this->commit($this->source, 'remote.txt', 'remote addition');
        }
        $local = $this->commit($this->project, 'local.txt', 'local addition');

        $this->assertPullFails($this->service(), 'fast-forward');

        self::assertSame($local, $this->git(['rev-parse', 'HEAD']));
        self::assertSame('local addition', file_get_contents($this->project.'/local.txt'));
        self::assertSame('', $this->git(['status', '--porcelain']));
    }

    public static function nonFastForwardHistories(): iterable
    {
        yield 'ahead' => [false];
        yield 'diverged' => [true];
    }

    public function testConcurrentUpdateLockIsRespectedAndRemainsReusable(): void
    {
        $lock = fopen($this->project.'/.git/aggregate-update.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->assertPullFails($this->service(), 'already running');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertFalse($this->service()->pull()['changed']);
    }

    public function testGitHooksAreNotExecutedByPull(): void
    {
        $this->commit($this->source, 'application.txt', 'new application');
        $hook = $this->project.'/.git/hooks/post-merge';
        file_put_contents($hook, "#!/bin/sh\nprintf executed > hook-executed.txt\n");
        chmod($hook, 0700);

        self::assertTrue($this->service()->pull()['changed']);
        self::assertFileDoesNotExist($this->project.'/hook-executed.txt');
    }

    public function testPullPreservesConfiguredSignatureVerification(): void
    {
        $this->commit($this->source, 'application.txt', 'unsigned update');
        $this->git(['config', 'merge.verifySignatures', 'true']);

        $this->assertPullFails($this->service(), 'fast-forward');

        self::assertSame($this->initial, $this->git(['rev-parse', 'HEAD']));
        self::assertSame('initial version', file_get_contents($this->project.'/application.txt'));
    }

    public function testPullRefusesAnInProgressMergeAndHiddenIndexChanges(): void
    {
        file_put_contents($this->project.'/.git/MERGE_HEAD', $this->initial."\n");
        $this->assertPullFails($this->service(), 'in progress');
        unlink($this->project.'/.git/MERGE_HEAD');
        $this->git(['update-index', '--assume-unchanged', 'application.txt']);
        file_put_contents($this->project.'/application.txt', 'hidden local change');

        $this->assertPullFails($this->service(), 'index flags');

        self::assertSame('hidden local change', file_get_contents($this->project.'/application.txt'));
        self::assertSame($this->initial, $this->git(['rev-parse', 'HEAD']));
    }

    public function testMissingUpstreamBranchFailsWithoutChangingTheInstallation(): void
    {
        $this->git(['switch', '--quiet', '-c', 'local-only']);

        $this->assertPullFails($this->service(branch: 'local-only'), 'configured updates_branch on GitHub');

        self::assertSame($this->initial, $this->git(['rev-parse', 'HEAD']));
        self::assertSame('local-only', $this->git(['branch', '--show-current']));
    }

    private function service(?MockHttpClient $client = null, string $token = '', mixed $branch = 'deployment/stable', ?string $project = null): ApplicationUpdateService
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn(['updates_branch' => $branch]);

        return new ApplicationUpdateService($project ?? $this->project, $client ?? new MockHttpClient([]), $this->cache, $this->clock, $token, new UpdateSettings($config));
    }

    private function archiveService(string $project, MockHttpClient $client): ApplicationUpdateService
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn([]);
        $settings = new UpdateSettings($config);
        $releases = new ReleaseUpdateService(new InstalledRelease($project), $client, $this->cache, $this->clock, $settings);

        return new ApplicationUpdateService($project, $client, $this->cache, $this->clock, '', $settings, $releases);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    private function assertPullFails(ApplicationUpdateService $service, string $message): void
    {
        try {
            $service->pull();
            self::fail('The unsafe update should have been refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    private function commit(string $repository, string $file, string $contents): string
    {
        file_put_contents($repository.'/'.$file, $contents);
        $this->git(['add', '--all'], $repository);
        $this->git(['commit', '--quiet', '--message=fixture update'], $repository);

        return $this->git(['rev-parse', 'HEAD'], $repository);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, ?string $repository = null): string
    {
        $process = new Process([
            'git', '-c', 'user.name=Update Test', '-c', 'user.email=updates@example.test',
            '-c', 'commit.gpgSign=false', '-c', 'core.hooksPath=/dev/null', ...$arguments,
        ], $repository ?? $this->project, timeout: 15);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
