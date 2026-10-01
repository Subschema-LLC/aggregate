<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\ApplicationUpdateService;
use App\Service\FeatureFlags;
use App\Service\Update\DeploymentState;
use App\Service\Update\LocalConfigOverrides;
use App\Service\Update\ReleasePackageInstaller;
use App\Service\UpdateSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

/** Update status for code deployed another way (Plesk Git, cPanel, CI/CD): read-only. */
final class DeploymentUpdateStatusTest extends TestCase
{
    private string $directory;
    private string $project;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-deployed-'.bin2hex(random_bytes(8));
        $this->project = $this->directory.'/site';
        mkdir($this->project.'/src', 0775, true);
        file_put_contents($this->project.'/src/App.php', '<?php // one');
    }

    protected function tearDown(): void
    {
        (new ReleasePackageInstaller($this->directory, new LocalConfigOverrides($this->directory)))->removeTree($this->directory);
    }

    public function testTheMethodFitsAnyDirectoryAndNeverPullsCode(): void
    {
        $service = $this->service(new MockHttpClient([]));
        $source = $service->source();

        self::assertSame(['deployment', 'deployment', null], [$source['source'], $source['method'], $source['mismatch']]);
        self::assertSame('deployment', $service->installationType());
        self::assertFalse($service->isReleaseInstallation());
        self::assertStringContainsString('deployed another way', (string) $service->pullProblem());

        mkdir($this->project.'/.git');
        self::assertNull($this->service(new MockHttpClient([]))->source()['mismatch'], 'A Git clone fits too.');
        $this->expectExceptionMessage('app:updates:deployed');
        $service->pull();
    }

    public function testWithoutARecordOrRepositoryTheCommitIsUnknownAndStepsArePending(): void
    {
        $client = new MockHttpClient([$this->json(['sha' => str_repeat('b', 40)])]);

        $status = $this->service($client)->check();

        self::assertSame('deployment', $status['installation_type']);
        self::assertSame('unknown', $status['state']);
        self::assertNull($status['current_commit']);
        self::assertTrue($status['deployment_pending']);
        self::assertNull($status['deployed_at']);
        self::assertStringContainsString('--git-dir', $status['message']);
        self::assertSame(str_repeat('b', 40), $status['latest_commit']);
    }

    public function testRecordedCommitIsComparedWithTheBranch(): void
    {
        $deployed = str_repeat('a', 40);
        $latest = str_repeat('b', 40);
        $state = new DeploymentState($this->project);
        $state->save($deployed, 'master', $state->fingerprint(), 'run-1');
        $client = new MockHttpClient([
            $this->json(['sha' => $latest]),
            $this->json(['status' => 'ahead', 'ahead_by' => 3, 'behind_by' => 0]),
        ]);

        $status = $this->service($client)->check();

        self::assertSame('available', $status['state']);
        self::assertSame(3, $status['commits_behind']);
        self::assertSame($deployed, $status['current_commit']);
        self::assertSame('record', $status['commit_source']);
        self::assertFalse($status['deployment_pending']);
        self::assertStringContainsString('3 newer commits are on master', $status['message']);
        self::assertSame(ApplicationUpdateService::REPOSITORY_URL.'/compare/'.$deployed.'...'.$latest, $status['compare_url']);
        self::assertSame(2, $client->getRequestsCount());

        $upToDate = $this->service(new MockHttpClient([$this->json(['sha' => $deployed])]))->check();
        self::assertSame(['up_to_date', 0], [$upToDate['state'], $upToDate['commits_behind']]);
    }

    public function testChangedFilesAreReadFromTheRepositoryAndReportedAsPending(): void
    {
        $this->git(['init', '--quiet', '--initial-branch=master']);
        $head = $this->commit();
        $state = new DeploymentState($this->project);
        $state->save(str_repeat('a', 40), 'master', $state->fingerprint(), 'run-1');
        file_put_contents($this->project.'/src/App.php', '<?php // two');

        $status = $this->service(new MockHttpClient([$this->json(['sha' => $head])]))->check();

        self::assertTrue($status['deployment_pending']);
        self::assertSame($head, $status['current_commit']);
        self::assertSame('repository', $status['commit_source']);
        self::assertSame($this->project, $status['deployment_repository']);
        self::assertSame(str_repeat('a', 40), $status['deployed_commit']);
        self::assertSame('up_to_date', $status['state']);
    }

    private function service(MockHttpClient $client): ApplicationUpdateService
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('all')->willReturn(['updates_method' => 'deployment']);
        $clock = new MockClock('2026-09-30 12:00:00 UTC');

        return new ApplicationUpdateService(
            $this->project, $client, new ArrayAdapter(clock: $clock), $clock, new FeatureFlags($config), '',
            new UpdateSettings($config), deployment: new DeploymentState($this->project),
        );
    }

    private function json(array $data): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function commit(): string
    {
        $this->git(['add', '--all']);
        $this->git(['commit', '--quiet', '--message=fixture']);

        return $this->git(['rev-parse', 'HEAD']);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = new Process(['git', '-c', 'user.name=Deploy Test', '-c', 'user.email=deploy@example.test', '-c', 'commit.gpgSign=false', '-c', 'core.hooksPath=/dev/null', ...$arguments], $this->project, timeout: 15);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
