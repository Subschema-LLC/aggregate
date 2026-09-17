<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WebsiteConfigManager;
use App\Service\WebsiteDomainPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class WebsiteConfigManagerTest extends TestCase
{
    private string $projectDir;
    private string $configPath;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-websites-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
        $this->configPath = $this->projectDir.'/config/websites.yaml';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectDir.'/config/*') ?: [] as $file) {
            if (is_dir($file)) {
                @rmdir($file);
            } else {
                @unlink($file);
            }
        }
        @unlink($this->projectDir.'/shared.yaml');
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    public function testAddsAnExplicitPolicyAndPreservesOtherYamlData(): void
    {
        $existing = [
            'name' => 'Existing', 'domain' => 'existing.example', 'public_token' => 'legacy-token',
            'operator_metadata' => ['owner' => 'Operations', 'enabled' => true],
            'domain_policy' => ['mode' => 'restricted', 'domains' => ['existing.example']],
        ];
        $this->writeConfig(['operator' => ['region' => 'test'], 'websites' => [$existing]]);
        $manager = new WebsiteConfigManager($this->projectDir);

        self::assertTrue($manager->addWebsite(' New website ', 'HTTPS://Example.COM/', 'new-token', [
            'mode' => 'restricted', 'domains' => ['EXAMPLE.COM.', '*.SHOP.EXAMPLE.COM'],
        ]));

        $saved = Yaml::parseFile($this->configPath);
        self::assertSame(['region' => 'test'], $saved['operator']);
        self::assertSame($existing, $saved['websites'][0]);
        self::assertSame([
            'name' => 'New website', 'domain' => 'example.com', 'token' => 'new-token',
            'domain_policy' => ['mode' => 'restricted', 'domains' => ['example.com', '*.shop.example.com']],
        ], $saved['websites'][1]);
        self::assertSame($saved['websites'][1], $manager->findOneByToken('new-token'));
    }

    public function testOldCallersKeepImplicitLegacyRestrictions(): void
    {
        $manager = new WebsiteConfigManager($this->projectDir);
        self::assertSame([], $manager->getWebsites());
        self::assertTrue($manager->addWebsite('Example', 'example.com'));
        $website = $manager->getWebsites()[0];
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $website['token']);
        self::assertArrayNotHasKey('domain_policy', $website);
        self::assertTrue((new WebsiteDomainPolicy())->allows($website, 'https://a.b.example.com'));
        self::assertFalse((new WebsiteDomainPolicy())->allows($website, 'https://unrelated.test'));
    }

    public function testUpdatingPolicyPreservesTokenOtherFieldsAndRegistrations(): void
    {
        $site = ['name' => 'Legacy', 'domain' => 'example.com', 'public_token' => 'stable-token', 'extra' => ['keep' => true]];
        $other = ['name' => 'Other', 'domain' => 'other.example', 'token' => 'other-token', 'future_setting' => 42];
        $this->writeConfig(['operator_setting' => ['keep' => 'value'], 'websites' => [$site, $other]]);
        $manager = new WebsiteConfigManager($this->projectDir);

        self::assertTrue($manager->updateDomainPolicy('stable-token', ['mode' => 'all']));
        $saved = Yaml::parseFile($this->configPath);
        self::assertSame($site + ['domain_policy' => ['mode' => 'all', 'domains' => []]], $saved['websites'][0]);
        self::assertSame($other, $saved['websites'][1]);
        self::assertSame(['keep' => 'value'], $saved['operator_setting']);
        self::assertSame('stable-token', $manager->findOneByToken('stable-token')['token']);
        self::assertTrue((new WebsiteDomainPolicy())->allows($manager->findOneByToken('stable-token'), null));

        self::assertTrue($manager->updateDomainPolicy('stable-token', ['mode' => 'restricted', 'domains' => ['shop.example.com']]));
        $website = $manager->findOneByToken('stable-token');
        self::assertSame('stable-token', $website['token']);
        self::assertFalse((new WebsiteDomainPolicy())->allows($website, 'https://example.com'));
        self::assertTrue((new WebsiteDomainPolicy())->allows($website, 'https://shop.example.com'));
    }

    public function testReadsPreserveAnExplicitInvalidPolicyAndAllowItToBeRepaired(): void
    {
        foreach ([null, false, 'all', ['mode' => 'restricted', 'domains' => ['*']]] as $invalid) {
            $this->writeConfig(['websites' => [[
                'name' => 'Example', 'domain' => 'example.com', 'token' => 'token', 'domain_policy' => $invalid,
            ]]]);
            $manager = new WebsiteConfigManager($this->projectDir);
            $website = $manager->findOneByToken('token');
            self::assertNotNull($website);
            self::assertArrayHasKey('domain_policy', $website);
            self::assertSame($invalid, $website['domain_policy']);
            self::assertFalse((new WebsiteDomainPolicy())->allows($website, 'https://example.com'));
            self::assertTrue($manager->updateDomainPolicy('token', ['mode' => 'restricted', 'domains' => ['example.com']]));
            self::assertTrue((new WebsiteDomainPolicy())->allows($manager->findOneByToken('token'), 'https://example.com'));
        }
    }

    public function testPolicyValidationErrorsDoNotChangeExistingConfiguration(): void
    {
        $this->writeConfig(['websites' => [['name' => 'Example', 'domain' => 'example.com', 'token' => 'token']]]);
        $before = file_get_contents($this->configPath);
        $manager = new WebsiteConfigManager($this->projectDir);
        foreach (['add', 'update'] as $operation) {
            try {
                $invalidPolicy = ['mode' => 'all', 'domains' => ['*']];
                if ($operation === 'add') {
                    $manager->addWebsite('Another', 'another.example', 'another-token', $invalidPolicy);
                } else {
                    $manager->updateDomainPolicy('token', $invalidPolicy);
                }
                self::fail('An invalid domain policy was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, file_get_contents($this->configPath));
            }
        }
    }

    public function testDuplicateTokensCannotSelectOrEditAnAmbiguousRegistration(): void
    {
        $this->writeConfig(['websites' => [
            ['name' => 'First', 'domain' => 'first.example', 'token' => 'duplicate'],
            ['name' => 'Second', 'domain' => 'second.example', 'public_token' => 'duplicate'],
            ['name' => 'Unique', 'domain' => 'unique.example', 'token' => 'unique'],
        ]]);
        $before = file_get_contents($this->configPath);
        $manager = new WebsiteConfigManager($this->projectDir);
        self::assertNull($manager->findOneByToken('duplicate'));
        self::assertNotNull($manager->findOneByToken('unique'));
        foreach (['add', 'update', 'remove'] as $operation) {
            try {
                match ($operation) {
                    'add' => $manager->addWebsite('New', 'new.example', 'new-token'),
                    'update' => $manager->updateDomainPolicy('duplicate', ['mode' => 'all']),
                    'remove' => $manager->removeWebsite('duplicate'),
                };
                self::fail('An ambiguous token configuration was changed.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, file_get_contents($this->configPath));
            }
        }
    }

    public function testCannotCreateARegistrationWithAnExistingLegacyToken(): void
    {
        $this->writeConfig(['websites' => [['name' => 'Old', 'domain' => 'old.example', 'public_token' => 'token']]]);
        $before = file_get_contents($this->configPath);
        try {
            (new WebsiteConfigManager($this->projectDir))->addWebsite('New', 'new.example', 'token');
            self::fail('An existing website token was reused.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, file_get_contents($this->configPath));
        }
    }

    public function testRemovePreservesOtherPoliciesAndTopLevelConfiguration(): void
    {
        $other = ['name' => 'Other', 'domain' => 'other.example', 'token' => 'other', 'domain_policy' => ['mode' => 'all'], 'custom' => true];
        $this->writeConfig(['operator' => 'keep', 'websites' => [
            ['name' => 'Remove', 'domain' => 'remove.example', 'public_token' => 'remove'], $other,
        ]]);
        $manager = new WebsiteConfigManager($this->projectDir);

        self::assertTrue($manager->removeWebsite('remove'));
        self::assertSame(['operator' => 'keep', 'websites' => [$other]], Yaml::parseFile($this->configPath));
        $before = file_get_contents($this->configPath);
        self::assertFalse($manager->removeWebsite('missing'));
        self::assertFalse($manager->updateDomainPolicy('missing', ['mode' => 'all']));
        self::assertSame($before, file_get_contents($this->configPath));
        self::assertNull($manager->findOneByToken(''));
    }

    #[DataProvider('malformedDocuments')]
    public function testInvalidExistingYamlFailsClosedAndIsNeverReplaced(string $yaml): void
    {
        file_put_contents($this->configPath, $yaml);
        $manager = new WebsiteConfigManager($this->projectDir);
        self::assertSame([], $manager->getWebsites());
        self::assertNull($manager->findOneByToken('token'));
        self::assertFalse($manager->addWebsite('New', 'new.example', 'new-token'));
        self::assertFalse($manager->updateDomainPolicy('token', ['mode' => 'all']));
        self::assertFalse($manager->removeWebsite('token'));
        self::assertSame($yaml, file_get_contents($this->configPath));
    }

    public static function malformedDocuments(): iterable
    {
        yield 'broken YAML' => ["websites: [broken\n"];
        yield 'scalar root' => ["false\n"];
        yield 'explicit null root' => ["null\n"];
        yield 'list root' => ["- name: Example\n"];
        yield 'null website list' => ["websites: null\n"];
        yield 'scalar website list' => ["websites: invalid\n"];
        yield 'mapping website list' => ["websites:\n  website:\n    token: token\n"];
        yield 'scalar website entry' => ["websites: [invalid]\n"];
        yield 'list website entry' => ["websites: [[example.com, token]]\n"];
        yield 'wrong token type' => ["websites:\n  - domain: example.com\n    token: true\n"];
        yield 'wrong domain type' => ["websites:\n  - domain: [example.com]\n    token: token\n"];
    }

    public function testACommentsOnlyFileCanBeInitialized(): void
    {
        file_put_contents($this->configPath, "# Operator registrations\n\n  # Add websites below\n");
        self::assertTrue((new WebsiteConfigManager($this->projectDir))->addWebsite('New', 'new.example', 'token', ['mode' => 'all']));
        self::assertSame('token', Yaml::parseFile($this->configPath)['websites'][0]['token']);
    }

    public function testIoFailuresDoNotReportSuccessfulWrites(): void
    {
        $missingParent = new WebsiteConfigManager($this->projectDir.'/missing');
        self::assertSame([], $missingParent->getWebsites());
        self::assertFalse($missingParent->addWebsite('New', 'new.example', 'token'));
        self::assertFalse($missingParent->updateDomainPolicy('token', ['mode' => 'all']));
        self::assertTrue(mkdir($this->configPath));
        $directoryAsFile = new WebsiteConfigManager($this->projectDir);
        self::assertSame([], $directoryAsFile->getWebsites());
        self::assertFalse($directoryAsFile->addWebsite('New', 'new.example', 'token'));
        self::assertDirectoryExists($this->configPath);
    }

    public function testUpdatesPreserveSymlinkAndExistingFilePermissions(): void
    {
        $this->writeConfig(['websites' => [['name' => 'Example', 'domain' => 'example.com', 'token' => 'token']]]);
        self::assertTrue(chmod($this->configPath, 0640));
        self::assertTrue(rename($this->configPath, $this->projectDir.'/shared.yaml'));
        self::assertTrue(symlink('../shared.yaml', $this->configPath));
        self::assertTrue(chmod($this->projectDir.'/config', 0500));

        try {
            self::assertTrue((new WebsiteConfigManager($this->projectDir))->updateDomainPolicy('token', ['mode' => 'all']));
        } finally {
            chmod($this->projectDir.'/config', 0700);
        }

        self::assertTrue(is_link($this->configPath));
        self::assertSame(0640, fileperms($this->projectDir.'/shared.yaml') & 0777);
        self::assertSame(['mode' => 'all', 'domains' => []], Yaml::parseFile($this->projectDir.'/shared.yaml')['websites'][0]['domain_policy']);
    }

    public function testConcurrentUpdateWaitsForLockAndUsesTheLatestDocument(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('Subprocess support is required to verify concurrent configuration writes.');
        }
        $initial = ['websites' => [['name' => 'Example', 'domain' => 'example.com', 'token' => 'token']]];
        $this->writeConfig($initial);
        $handle = fopen($this->configPath, 'r+b');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX));
        $script = <<<'PHP'
require $argv[1];
echo "ready\n";
flush();
$manager = new App\Service\WebsiteConfigManager($argv[2]);
exit($manager->updateDomainPolicy('token', ['mode' => 'all']) ? 0 : 1);
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2).'/vendor/autoload.php', $this->projectDir]);
        $process->setTimeout(10);
        try {
            $process->start();
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready')));
            self::assertTrue($process->isRunning());
            // Another cooperating writer adds fields while the policy update
            // waits; the waiting update must read them after acquiring the lock.
            $initial['operator'] = ['written_by' => 'concurrent writer'];
            $initial['websites'][0]['other_setting'] = 'retain me';
            self::assertTrue(ftruncate($handle, 0));
            self::assertNotFalse(fwrite($handle, Yaml::dump($initial, 6, 2)));
            self::assertTrue(fflush($handle));
            self::assertTrue(flock($handle, LOCK_UN));
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            if ($process->isRunning()) {
                $process->stop();
            }
        }

        $saved = Yaml::parseFile($this->configPath);
        self::assertSame(['written_by' => 'concurrent writer'], $saved['operator']);
        self::assertSame('retain me', $saved['websites'][0]['other_setting']);
        self::assertSame(['mode' => 'all', 'domains' => []], $saved['websites'][0]['domain_policy']);
    }

    private function writeConfig(array $config): void
    {
        file_put_contents($this->configPath, Yaml::dump($config, 6, 2));
    }
}
