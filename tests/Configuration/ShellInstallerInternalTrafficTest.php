<?php

declare(strict_types=1);

namespace App\Tests\Configuration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class ShellInstallerInternalTrafficTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-shell-install-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config', 0700, true));
    }

    protected function tearDown(): void
    {
        @unlink($this->projectDir.'/config/aggregate.yaml');
        @rmdir($this->projectDir.'/config');
        @rmdir($this->projectDir);
    }

    public function testHeadlessShellInstallCreatesDistinctEnvironmentTokens(): void
    {
        $this->runConfigurationBlock("https://analytics.example.com\nAggregate\nn\n");

        $config = Yaml::parseFile($this->projectDir.'/config/aggregate.yaml');
        self::assertSame('cookie', $config['internal_traffic_storage']);
        self::assertSame('orgInternalTraffic', $config['internal_traffic_name']);
        self::assertSame('true', $config['internal_traffic_value']);
        self::assertSame('', $config['internal_traffic_cookie_domain']);
        self::assertFalse($config['environments']['prod']['dashboard_enabled']);

        $tokens = [];
        foreach (['prod', 'dev', 'test'] as $environment) {
            $tokens[] = $token = $config['environments'][$environment]['internal_traffic_share_token'];
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        }
        self::assertCount(3, array_unique($tokens));
    }

    public function testExistingConfigurationAndTeamTokenAreNotRewritten(): void
    {
        $existing = "# Preserve operator formatting and settings.\ninternal_traffic_share_token: '".str_repeat('a', 64)."'\n";
        file_put_contents($this->projectDir.'/config/aggregate.yaml', $existing);

        $this->runConfigurationBlock('');

        self::assertSame($existing, file_get_contents($this->projectDir.'/config/aggregate.yaml'));
    }

    public function testShellInstallersUseRestrictivePermissionsAndVerifyComposerChecksum(): void
    {
        $install = (string) file_get_contents(dirname(__DIR__, 2).'/install.sh');
        $plesk = (string) file_get_contents(dirname(__DIR__, 2).'/plesk-setup.sh');

        self::assertStringNotContainsString('chmod -R 755 var/ public/', $install);
        self::assertStringNotContainsString('chmod -R 777', $plesk);
        self::assertStringNotContainsString('chmod -R 775', $plesk);
        self::assertStringContainsString('chmod 750 var var/cache var/log', $install);
        self::assertStringContainsString('chmod 750 var var/cache var/log', $plesk);
        self::assertStringContainsString('https://composer.github.io/installer.sig', $install);
        self::assertStringContainsString("hash_file('sha384', 'composer-setup.php')", $install);
    }

    private function runConfigurationBlock(string $input): void
    {
        $installer = file_get_contents(dirname(__DIR__, 2).'/install.sh');
        self::assertIsString($installer);
        $start = strpos($installer, 'if [ ! -f config/aggregate.yaml ]; then');
        $end = strpos($installer, '# Set up database');
        self::assertIsInt($start);
        self::assertIsInt($end);
        self::assertGreaterThan($start, $end);

        // Execute only initial YAML creation in a temporary directory. Never run
        // the installer's dependency, database, permission or admin operations.
        $block = substr($installer, $start, $end - $start);
        $process = new Process(['bash', '-c', "set -e\n".$block], $this->projectDir);
        $process->setInput($input);
        $process->mustRun();
        self::assertTrue($process->isSuccessful());
    }
}
