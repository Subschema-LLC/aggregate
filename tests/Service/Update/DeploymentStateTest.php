<?php

declare(strict_types=1);

namespace App\Tests\Service\Update;

use App\Service\Update\DeploymentState;
use App\Service\Update\LocalConfigOverrides;
use App\Service\Update\ReleasePackageInstaller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DeploymentStateTest extends TestCase
{
    private string $directory;
    private string $project;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aggregate-deployment-'.bin2hex(random_bytes(8));
        $this->project = $this->directory.'/site';
        foreach ([
            'src/App.php' => '<?php // one',
            'templates/base.html.twig' => '{# one #}',
            'config/packages/framework.yaml' => "framework: ~\n",
            'composer.lock' => '{}',
        ] as $path => $content) {
            $this->put($path, $content);
        }
    }

    protected function tearDown(): void
    {
        (new ReleasePackageInstaller($this->directory, new LocalConfigOverrides($this->directory)))->removeTree($this->directory);
    }

    public function testFingerprintFollowsDeployedCodeAndIgnoresOperatorAndGeneratedFiles(): void
    {
        $state = new DeploymentState($this->project);
        $initial = $state->fingerprint();

        // The operator's files, runtime data and generated files are not part of a deployment.
        $this->put('config/aggregate.yaml', "app_host: example.test\n");
        $this->put('config/goals.local.yaml', "parameters: {}\n");
        $this->put('.env.local', "APP_SECRET=x\n");
        $this->put('var/cache/prod/container.php', '<?php');
        $this->put('assets/vendor/stimulus/stimulus.js', '// downloaded');
        $this->put('config/reference.php', '<?php // generated');
        $this->put('vendor/autoload.php', '<?php');
        self::assertSame($initial, $state->fingerprint());

        $this->put('src/App.php', '<?php // two');
        $changed = $state->fingerprint();
        self::assertNotSame($initial, $changed);
        $this->put('migrations/Version1.php', '<?php');
        self::assertNotSame($changed, $state->fingerprint());
    }

    public function testRecordsTheLastRunAndNoticesChangedFiles(): void
    {
        $state = new DeploymentState($this->project);
        self::assertNull($state->record());
        self::assertTrue($state->changedSinceRecord(), 'Files never finished count as changed.');

        $commit = str_repeat('c', 40);
        $state->save($commit, 'master', $state->fingerprint(), 'run-1');
        $record = $state->record();
        self::assertSame([$commit, 'master', 'run-1'], [$record['commit'], $record['branch'], $record['update']]);
        self::assertFalse($state->changedSinceRecord());

        $this->put('templates/base.html.twig', '{# two #}');
        self::assertTrue($state->changedSinceRecord());

        $state->save('not-a-commit', null, $state->fingerprint(), 'run-2');
        self::assertNull($state->record()['commit']);
        $this->put(DeploymentState::RECORD, '{broken');
        self::assertNull($state->record());
    }

    public function testReadsTheDeployedCommitFromBareRepositoriesAndClones(): void
    {
        $source = $this->directory.'/source';
        mkdir($source);
        $this->git(['init', '--quiet', '--initial-branch=master'], $source);
        file_put_contents($source.'/README.md', 'one');
        $first = $this->commit($source);
        file_put_contents($source.'/README.md', 'two');
        $second = $this->commit($source);
        $this->git(['branch', 'development', $first], $source);
        $state = new DeploymentState($this->project);

        // Plesk keeps a bare repository and deploys updates_branch from it.
        $bare = $this->directory.'/git/aggregate.git';
        $this->git(['clone', '--quiet', '--bare', $source, $bare], $this->directory);
        self::assertSame(['commit' => $second, 'branch' => 'master'], $state->readRepository($bare, 'master'));
        self::assertSame($first, $state->readRepository($bare.'/', 'development')['commit']);
        $this->git(['pack-refs', '--all'], $bare);
        self::assertFileDoesNotExist($bare.'/refs/heads/master');
        self::assertSame($second, $state->readRepository($bare, 'master')['commit'], 'Packed references are read too.');

        // cPanel deploys from a clone with a working tree: its checked-out commit counts.
        $clone = $this->directory.'/repositories/aggregate';
        $this->git(['clone', '--quiet', '--branch=development', $source, $clone], $this->directory);
        self::assertSame(['commit' => $first, 'branch' => 'development'], $state->readRepository($clone, 'master'));
        $this->git(['checkout', '--quiet', '--detach', $second], $clone);
        self::assertSame(['commit' => $second, 'branch' => null], $state->readRepository($clone, 'master'));

        try {
            $state->readRepository($bare, 'missing');
            self::fail('A missing branch must be reported.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Set updates_branch', $e->getMessage());
        }
        $this->expectExceptionMessage('is not a Git repository');
        $state->readRepository($this->project, 'master');
    }

    public function testFindsTheRepositoryWithoutSettings(): void
    {
        $state = new DeploymentState($this->project);
        self::assertNull($state->repository());
        self::assertSame([], $state->candidates(), 'Only hosting panel layouts are searched.');

        mkdir($this->project.'/.git');
        self::assertSame($this->project, $state->repository(), 'A clone in the application directory deploys itself.');
    }

    public function testComparesInstalledDependenciesWithTheLockFile(): void
    {
        $state = new DeploymentState($this->project);
        $lock = [
            'packages' => [['name' => 'vendor/runtime', 'version' => '1.0.0', 'dist' => ['reference' => 'aaa']]],
            'packages-dev' => [['name' => 'vendor/tests', 'version' => '2.0.0', 'dist' => ['reference' => 'bbb']]],
        ];
        $this->put('composer.lock', json_encode($lock));
        self::assertTrue($state->dependenciesOutOfDate(false), 'Missing vendor/ is out of date.');

        $this->put('vendor/autoload.php', '<?php');
        $this->put('vendor/composer/installed.json', json_encode([
            'packages' => [
                ['name' => 'vendor/runtime', 'version' => '1.0.0', 'dist' => ['reference' => 'aaa']],
                ['name' => 'vendor/tests', 'version' => '2.0.0', 'dist' => ['reference' => 'bbb']],
            ],
            'dev-package-names' => ['vendor/tests'],
        ]));
        self::assertFalse($state->dependenciesOutOfDate(false));
        self::assertFalse($state->dependenciesOutOfDate(true));

        $lock['packages'][0]['version'] = '1.1.0';
        $this->put('composer.lock', json_encode($lock));
        self::assertTrue($state->dependenciesOutOfDate(false));
    }

    private function put(string $path, string $content): void
    {
        $target = $this->project.'/'.$path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        file_put_contents($target, $content);
    }

    private function commit(string $repository): string
    {
        $this->git(['add', '--all'], $repository);
        $this->git(['commit', '--quiet', '--message=fixture'], $repository);

        return $this->git(['rev-parse', 'HEAD'], $repository);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, string $directory): string
    {
        $process = new Process(['git', '-c', 'user.name=Deploy Test', '-c', 'user.email=deploy@example.test', '-c', 'commit.gpgSign=false', '-c', 'core.hooksPath=/dev/null', ...$arguments], $directory, timeout: 15);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
