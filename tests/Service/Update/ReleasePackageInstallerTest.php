<?php

declare(strict_types=1);

namespace App\Tests\Service\Update;

use App\Service\Update\FileTransaction;
use App\Service\Update\LocalConfigOverrides;
use App\Service\Update\ReleasePackageInstaller;
use PHPUnit\Framework\TestCase;

final class ReleasePackageInstallerTest extends TestCase
{
    private string $directory;
    private string $project;

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('Release installation requires the zip extension.');
        }
        $this->directory = sys_get_temp_dir().'/aggregate-installer-'.bin2hex(random_bytes(8));
        $this->project = $this->directory.'/installation';
        mkdir($this->project, 0775, true);
    }

    protected function tearDown(): void
    {
        (new ReleasePackageInstaller($this->project, new LocalConfigOverrides($this->project)))->removeTree($this->directory);
    }

    public function testUpdatePreservesOperatorFilesMovesConfigEditsAndRollsBackExactly(): void
    {
        $this->install($this->version1());
        // Operator state that is not part of any release.
        $this->put('config/aggregate.yaml', "app_host: example.test\n");
        $this->put('config/websites.yaml', "websites: []\n");
        $this->put('config/tag-manager/sites/aaaaaaaaaaaaaaaaaaaaaaaa.yaml', "tag_manager: {}\n");
        $this->put('.env.local', "APP_SECRET=operator-secret\n");
        $this->put('var/data.db', 'database');
        $this->put('var/branding/logo.png', 'logo');
        $this->put('config/navigation.local.yaml', "parameters: { app.main_navigation: { items: [] } }\n");
        // Operator edits to shipped files.
        $this->put('config/goals.yaml', "parameters:\n    app.goal_events: { purchase: {}, operator_goal: {} }\n");
        $this->put('public/.htaccess', "# operator rewrite rules\n");
        $this->put('.env', "APP_ENV=prod\nAPP_SECRET=kept-value\n");
        $this->put('NOTES.md', 'operator notes on a file the release stops shipping');
        $this->put('src/Operator.php', '<?php // stray code in a release-owned tree');
        $this->put('config/release-signing.pub', "installed-trusted-key\n");
        $before = $this->snapshot();

        $installer = $this->installer();
        $staging = $this->directory.'/staging';
        $installer->stage($this->zip($this->version2()), $staging);
        $plan = $installer->plan($staging);
        $files = new FileTransaction($this->project, $this->directory.'/backup');
        $report = $installer->apply($plan, $staging, $files);

        // Operator-owned files are untouched.
        foreach (['config/aggregate.yaml', 'config/websites.yaml', 'config/tag-manager/sites/aaaaaaaaaaaaaaaaaaaaaaaa.yaml', '.env.local', 'var/data.db', 'var/branding/logo.png', 'config/navigation.local.yaml', 'config/release-signing.pub', 'public/.htaccess', 'NOTES.md'] as $path) {
            self::assertSame($before[$path], $this->read($path), $path);
        }
        // Edited defaults moved to the override; the shipped default is current.
        self::assertSame("parameters:\n    app.goal_events: { purchase: {}, lead: {} }\n", $this->read('config/goals.yaml'));
        self::assertStringEndsWith($before['config/goals.yaml'], $this->read('config/goals.local.yaml'));
        self::assertSame(['config/goals.local.yaml'], $report['overrides_created']);
        // Unedited defaults are simply replaced.
        self::assertSame("parameters: { app.main_navigation: { items: [v2] } }\n", $this->read('config/navigation.yaml'));
        // .env keeps values and gains new keys.
        self::assertStringStartsWith("APP_ENV=prod\nAPP_SECRET=kept-value\n", $this->read('.env'));
        self::assertStringContainsString("NEW_SETTING=default\n", $this->read('.env'));
        self::assertSame(['NEW_SETTING'], $report['env_keys_added']);
        // Code is updated, stale code removed, and metadata written.
        self::assertSame('<?php // v2', $this->read('src/Kept.php'));
        self::assertSame('<?php // new', $this->read('src/Added.php'));
        self::assertFileDoesNotExist($this->project.'/src/Removed.php');
        self::assertFileDoesNotExist($this->project.'/src/Operator.php');
        self::assertFileDoesNotExist($this->project.'/README.md');
        self::assertSame(['NOTES.md'], $report['kept_unlisted']);
        self::assertSame(0755, fileperms($this->project.'/bin/console') & 0777);
        self::assertStringContainsString('"version": "2.0.0"', $this->read('release-files.json'));
        self::assertSame('{"version":"2.0.0"}', $this->read('release.json'));
        self::assertArrayHasKey('public/.htaccess', $report['kept']);
        self::assertArrayHasKey('config/release-signing.pub', $report['kept']);
        self::assertSame("# v2 rules\n", file_get_contents($this->directory.'/backup/incoming/public/.htaccess'));

        // Re-running the same apply (as after an interruption) changes nothing further.
        self::assertSame([], $installer->plan($staging)['writes']);

        $files->rollback();
        self::assertSame($before, $this->snapshot());
        self::assertDirectoryDoesNotExist($this->project.'/src/Added');
    }

    public function testLegacyInstallationWithoutInventoryKeepsDifferingConfigurationAsOverride(): void
    {
        foreach ($this->version1() as $path => $content) {
            if ($path !== 'release-files.json') {
                $this->put($path, $content);
            }
        }
        $installer = $this->installer();
        $staging = $this->directory.'/staging';
        $installer->stage($this->zip($this->version2()), $staging);
        $plan = $installer->plan($staging);

        self::assertTrue($plan['legacy']);
        // Without an inventory an unedited but older default cannot be told
        // apart from an edit, so it is kept as an override rather than lost.
        self::assertArrayHasKey('config/goals.yaml', $plan['overrides']);
        self::assertSame([], $plan['replaced_modified']);
        self::assertContains('src/Removed.php', $plan['deletes']);
    }

    public function testConflictingOverrideStopsPlanningBeforeAnyChange(): void
    {
        $this->install($this->version1());
        $this->put('config/goals.yaml', 'edited');
        $this->put('config/goals.local.yaml', 'different');
        $installer = $this->installer();
        $installer->stage($this->zip($this->version2()), $this->directory.'/staging');

        $this->expectExceptionMessage('already exists with different content');
        $installer->plan($this->directory.'/staging');
    }

    public function testPackagesWithOperatorPathsOrWithoutAMatchingInventoryAreRefused(): void
    {
        $withSecret = $this->version2();
        unset($withSecret['release-files.json']);
        $withSecret = $this->withInventory($withSecret + ['config/aggregate.yaml' => 'packaged operator configuration'], '2.0.0');
        $this->assertStageFails($this->zip($withSecret, 'secret.zip'), 'operator-owned path');

        $unlisted = $this->version2();
        $this->assertStageFails($this->zip($unlisted, 'unlisted.zip', ['src/Unlisted.php' => '<?php']), 'do not match its file inventory');

        $legacy = $this->version2();
        unset($legacy['release-files.json']);
        $this->assertStageFails($this->zip($legacy, 'legacy.zip'), 'predates automatic updates');
    }

    public function testTransactionNeverOverwritesTheOriginalBackupWhenAnApplyIsRepeated(): void
    {
        $this->put('src/Kernel.php', 'original');
        $first = new FileTransaction($this->project, $this->directory.'/backup');
        $first->putContents('src/Kernel.php', 'first attempt', 0644);
        $first->putContents('src/Nested/New.php', 'created', 0644);
        unset($first);
        // A new process continues the same interrupted update.
        $second = new FileTransaction($this->project, $this->directory.'/backup');
        $second->putContents('src/Kernel.php', 'second attempt', 0644);
        file_put_contents($this->directory.'/backup/changes.jsonl', '{"op":"repl', FILE_APPEND);

        $result = $second->rollback();

        self::assertSame('original', $this->read('src/Kernel.php'));
        self::assertFileDoesNotExist($this->project.'/src/Nested/New.php');
        self::assertDirectoryDoesNotExist($this->project.'/src/Nested');
        self::assertSame(['restored' => 1, 'removed' => 1], $result);
        self::assertSame(['restored' => 1, 'removed' => 0], $second->rollback(), 'Rollback can be repeated safely.');
    }

    public function testTransactionRefusesUnsafePathsAndSymlinkTargets(): void
    {
        $files = new FileTransaction($this->project, $this->directory.'/backup');
        foreach (['../outside.php', '/etc/passwd', 'src/../../outside.php', 'src//x.php'] as $path) {
            try {
                $files->putContents($path, 'x', 0644);
                self::fail('Unsafe path accepted: '.$path);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('unsafe', $e->getMessage());
            }
        }
        file_put_contents($this->directory.'/elsewhere', 'outside');
        mkdir($this->project.'/public');
        symlink($this->directory.'/elsewhere', $this->project.'/public/index.php');

        $this->expectExceptionMessage('symbolic link');
        $files->putContents('public/index.php', 'x', 0644);
    }

    /** @return array<string, string> */
    private function version1(): array
    {
        return $this->withInventory([
            'LICENSE' => 'license',
            'README.md' => 'readme v1',
            'NOTES.md' => 'notes v1',
            'bin/console' => '<?php // console v1',
            'src/Kept.php' => '<?php // v1',
            'src/Removed.php' => '<?php // removed in v2',
            'config/goals.yaml' => "parameters:\n    app.goal_events: { purchase: {} }\n",
            'config/navigation.yaml' => "parameters: { app.main_navigation: { items: [v1] } }\n",
            'config/release-signing.pub' => "release-key-v1\n",
            'public/.htaccess' => "# v1 rules\n",
            '.env' => "APP_ENV=prod\nAPP_SECRET=\n",
            'release.json' => '{"version":"1.0.0"}',
        ], '1.0.0');
    }

    /** @return array<string, string> */
    private function version2(): array
    {
        return $this->withInventory([
            'LICENSE' => 'license',
            'bin/console' => '<?php // console v2',
            'src/Kept.php' => '<?php // v2',
            'src/Added.php' => '<?php // new',
            'config/goals.yaml' => "parameters:\n    app.goal_events: { purchase: {}, lead: {} }\n",
            'config/navigation.yaml' => "parameters: { app.main_navigation: { items: [v2] } }\n",
            'config/release-signing.pub' => "release-key-v2\n",
            'public/.htaccess' => "# v2 rules\n",
            '.env' => "APP_ENV=prod\nAPP_SECRET=\nNEW_SETTING=default\n",
            'release.json' => '{"version":"2.0.0"}',
        ], '2.0.0');
    }

    /** @param array<string, string> $files @return array<string, string> */
    private function withInventory(array $files, string $version): array
    {
        ksort($files);
        $files['release-files.json'] = json_encode([
            'schema' => 1,
            'version' => $version,
            'files' => array_map(static fn (string $content): string => hash('sha256', $content), $files),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $files;
    }

    /** @param array<string, string> $files */
    private function install(array $files): void
    {
        foreach ($files as $path => $content) {
            $this->put($path, $content);
        }
        chmod($this->project.'/bin/console', 0755);
    }

    /** @param array<string, string> $files @param array<string, string> $extra */
    private function zip(array $files, string $name = 'package.zip', array $extra = []): string
    {
        $path = $this->directory.'/'.$name;
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL));
        foreach ($files + $extra as $file => $content) {
            $zip->addFromString($file, $content);
            $zip->setExternalAttributesName($file, \ZipArchive::OPSYS_UNIX, (0100000 | ($file === 'bin/console' ? 0755 : 0644)) << 16);
        }
        $zip->close();

        return $path;
    }

    private function assertStageFails(string $zip, string $message): void
    {
        try {
            $this->installer()->stage($zip, $this->directory.'/staging-'.bin2hex(random_bytes(3)));
            self::fail('The package should be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    private function installer(): ReleasePackageInstaller
    {
        return new ReleasePackageInstaller($this->project, new LocalConfigOverrides($this->project));
    }

    private function put(string $path, string $content): void
    {
        $target = $this->project.'/'.$path;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        file_put_contents($target, $content);
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->project.'/'.$path);
    }

    /** @return array<string, string> Every file with its content and permissions */
    private function snapshot(): array
    {
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item->isFile()) {
                $files[substr($item->getPathname(), strlen($this->project) + 1)] = file_get_contents($item->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}
