<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AggregateConfigLoader;
use App\Service\UpdateSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpdateSettingsTest extends TestCase
{
    public function testBranchDefaultsToMasterWhenOmitted(): void
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn([]);

        self::assertSame('master', (new UpdateSettings($config))->branch());
    }

    #[DataProvider('validBranches')]
    public function testValidConfiguredBranchIsUsed(string $branch): void
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn(['updates_branch' => $branch]);

        self::assertSame($branch, (new UpdateSettings($config))->branch());
    }

    public static function validBranches(): iterable
    {
        foreach (['master', 'main', 'releases/stable', 'version-1.2', 'release_2026', 'issue#12'] as $branch) {
            yield $branch => [$branch];
        }
    }

    #[DataProvider('invalidBranches')]
    public function testExplicitInvalidBranchNeverFallsBackToMaster(mixed $branch): void
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn(['updates_branch' => $branch]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('updates_branch');

        (new UpdateSettings($config))->branch();
    }

    public static function invalidBranches(): iterable
    {
        foreach ([null, false, true, 123, [], '', ' master', 'master ', '-master', 'HEAD', '@',
            'release..stable', 'master@{1}', 'release//stable', '/master', 'master/', '.hidden',
            'release/.hidden', 'master.lock', 'release.lock/stable', 'master.', 'master~1',
            'master^', 'master:refs/heads/other', 'master?', 'master*', 'master[1', 'release\\stable',
            "master\n", str_repeat('x', 256)] as $index => $branch) {
            yield (string) $index => [$branch];
        }
    }

    public function testMalformedYamlCannotImplicitlySelectMaster(): void
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('assertHealthy')->willThrowException(new \RuntimeException('Application configuration is invalid.'));
        $config->expects(self::never())->method('all');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('configuration is invalid');

        (new UpdateSettings($config))->branch();
    }

    public function testRepositoryDefaultsAndValidation(): void
    {
        $settings = $this->settings([]);
        self::assertSame('Subschema-LLC/aggregate', $settings->repository());
        self::assertSame('https://github.com/Subschema-LLC/aggregate', $settings->repositoryUrl());
        self::assertTrue($settings->isOfficialRepository());

        $custom = $this->settings(['updates_repository' => 'example-org/aggregate.fork_1']);
        self::assertSame('example-org/aggregate.fork_1', $custom->repository());
        self::assertFalse($custom->isOfficialRepository());
        self::assertTrue($this->settings(['updates_repository' => 'subschema-llc/Aggregate'])->isOfficialRepository());
    }

    #[DataProvider('invalidRepositories')]
    public function testInvalidRepositoryIsAnError(string $key, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->settings([$key => $value])->repository();
    }

    public static function invalidRepositories(): iterable
    {
        foreach (['', 'owner', 'owner/', '/repo', 'owner/repo/extra', 'https://github.com/owner/repo', 'owner/repo.git', '-owner/repo', 'owner-/repo', 'ow--ner/repo', 'owner/..', 'owner/re po', null, ['owner/repo']] as $index => $repository) {
            yield 'repository '.$index => ['updates_repository', $repository];
        }
    }

    public function testSaveBranchWritesOnlyAValidatedBranch(): void
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn([]);
        $config->expects(self::once())->method('setMany')->with(['updates_branch' => 'releases/stable']);
        (new UpdateSettings($config))->saveBranch('releases/stable');

        $this->expectException(\InvalidArgumentException::class);
        (new UpdateSettings($config))->saveBranch('../x');
    }

    /** @param array<string, mixed> $values */
    private function settings(array $values): UpdateSettings
    {
        $config = $this->createMock(AggregateConfigLoader::class);
        $config->method('all')->willReturn($values);

        return new UpdateSettings($config);
    }
}
