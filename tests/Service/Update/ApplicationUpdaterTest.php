<?php

declare(strict_types=1);

namespace App\Tests\Service\Update;

use App\Service\AggregateConfigLoader;
use App\Service\ApplicationUpdateService;
use App\Service\FeatureFlags;
use App\Service\InstalledRelease;
use App\Service\ReleasePackageVerifier;
use App\Service\ReleaseUpdateService;
use App\Service\Update\ApplicationUpdater;
use App\Service\Update\LocalConfigOverrides;
use App\Service\Update\MaintenanceMode;
use App\Service\Update\ReleasePackageInstaller;
use App\Service\Update\SystemCheck;
use App\Service\Update\Toolchain;
use App\Service\Update\UpdateJournal;
use App\Service\UpdateSettings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

/**
 * Release updates against a disposable installation. The fixture console exits
 * without doing anything, which is how a new release that cannot continue the
 * update behaves; the successful end-to-end path runs against real packages.
 */
final class ApplicationUpdaterTest extends TestCase
{
    private string $directory;
    private string $project;
    private string $secretKey;
    private Connection $connection;
    /** @var list<array{0: string, 1: string}> */
    private array $messages = [];

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('sodium_crypto_sign_keypair') || !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Release updates require the zip, sodium and pdo_sqlite extensions.');
        }
        $this->directory = sys_get_temp_dir().'/aggregate-updater-'.bin2hex(random_bytes(8));
        $this->project = $this->directory.'/installation';
        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        foreach (['config', 'public', 'src', 'vendor', 'var'] as $directory) {
            mkdir($this->project.'/'.$directory, 0775, true);
        }
        foreach ($this->release('1.0.0') as $path => $content) {
            $this->put($path, $content);
        }
        chmod($this->project.'/bin/console', 0755);
        $this->put('config/release-signing.pub', base64_encode(sodium_crypto_sign_publickey($keypair))."\n");
        $this->put('config/aggregate.yaml', "app_host: example.test\n");
        $this->put('.env.local', "APP_SECRET=operator\n");
        $this->put('config/goals.yaml', "parameters: { app.goal_events: { operator: {} } }\n");
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->project.'/var/data.db']);
        $this->connection->executeStatement('CREATE TABLE events (id INTEGER PRIMARY KEY, name TEXT)');
        $this->connection->executeStatement("INSERT INTO events (name) VALUES ('before update')");
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
        if (isset($this->directory)) {
            (new ReleasePackageInstaller($this->project, new LocalConfigOverrides($this->project)))->removeTree($this->directory);
        }
    }

    public function testAnInvalidSignatureStopsBeforeAnythingChanges(): void
    {
        $before = $this->snapshot(except: 'var/');
        [$package, $manifest] = $this->package('2.0.0');
        file_put_contents($this->directory.'/bad.sig', base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES)));

        $state = $this->updater()->start(['package' => $package, 'manifest' => $manifest, 'signature' => $this->directory.'/bad.sig'], $this->record(...));

        self::assertSame('failed', $state['status']);
        self::assertSame('verify', $state['step']);
        self::assertStringContainsString('signature is invalid', $state['error']);
        self::assertNull((new MaintenanceMode($this->project))->status());
        self::assertSame($before, $this->snapshot(except: 'var/'));
    }

    public function testAFailureWhileInstallingFilesRestoresThemAutomatically(): void
    {
        [$package, $manifest, $signature] = $this->package('2.0.0');
        // A directory where the release ships a file makes the install fail midway.
        mkdir($this->project.'/src/Added.php');
        $before = $this->snapshot(except: 'var/');

        $state = $this->updater()->start(['package' => $package, 'manifest' => $manifest, 'signature' => $signature], $this->record(...));

        self::assertSame('failed', $state['status']);
        self::assertSame('apply_files', $state['step']);
        self::assertStringContainsString('Application files were restored', end($state['log'])['message']);
        self::assertSame($before, $this->snapshot(except: 'var/'));
        self::assertNull((new MaintenanceMode($this->project))->status());
        self::assertFileExists($this->project.'/'.$state['backup'].'/database.sqlite');
    }

    public function testUnfinishedUpdateStaysInMaintenanceUntilRolledBack(): void
    {
        [$package, $manifest, $signature] = $this->package('2.0.0');
        $before = $this->snapshot(except: 'var/');

        $state = $this->updater()->start(['package' => $package, 'manifest' => $manifest, 'signature' => $signature], $this->record(...));

        self::assertSame('needs_attention', $state['status']);
        self::assertSame('handoff', $state['step']);
        self::assertTrue($state['handed_off']);
        self::assertSame(['version' => '2.0.0', 'commit' => str_repeat('b', 40)], $state['to']);
        self::assertTrue((new MaintenanceMode($this->project))->status()['active']);
        self::assertNull((new MaintenanceMode($this->project))->status()['expires_at']);
        // Files are the new release, and configuration was preserved.
        self::assertSame('<?php // 2.0.0', $this->read('src/App.php'));
        self::assertSame("app_host: example.test\n", $this->read('config/aggregate.yaml'));
        self::assertSame("APP_SECRET=operator\n", $this->read('.env.local'));
        self::assertStringEndsWith("parameters: { app.goal_events: { operator: {} } }\n", $this->read('config/goals.local.yaml'));
        self::assertSame(['config/goals.local.yaml'], $state['report']['files']['overrides_created']);

        // A second update cannot start over an unresolved one.
        try {
            $this->updater()->start(['package' => $package, 'manifest' => $manifest, 'signature' => $signature], $this->record(...));
            self::fail('An unresolved update must block new updates.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('app:updates:apply --resume', $e->getMessage());
        }

        $this->connection->executeStatement("INSERT INTO events (name) VALUES ('after update')");
        $rolledBack = $this->updater()->rollback(true, $this->record(...));

        self::assertSame('rolled_back', $rolledBack['status']);
        self::assertSame($before, $this->snapshot(except: 'var/'));
        self::assertNull((new MaintenanceMode($this->project))->status());
        self::assertSame(['before update'], $this->connection->fetchFirstColumn('SELECT name FROM events'));
    }

    public function testAStoppedRollbackIsRepeatedNotResumedAsAnUpdate(): void
    {
        (new UpdateJournal($this->project))->write([
            'id' => 'u1', 'type' => 'release', 'status' => 'needs_attention', 'step' => 'rollback',
            'completed' => [], 'backup' => 'var/updates/backups/u1', 'environment' => 'prod', 'log' => [],
        ]);

        $this->expectExceptionMessage('app:updates:rollback again');
        $this->updater()->resume($this->record(...));
    }

    public function testInstallationWithoutReleaseMetadataIsAdoptedByItsFirstReleaseUpdate(): void
    {
        // Files copied by a deployment tool: no release.json or inventory.
        unlink($this->project.'/release.json');
        unlink($this->project.'/release-files.json');
        [$package, $manifest, $signature] = $this->package('2.0.0');

        $state = $this->updater()->start(['package' => $package, 'manifest' => $manifest, 'signature' => $signature], $this->record(...));

        self::assertSame('handoff', $state['step'], (string) ($state['error'] ?? ''));
        self::assertSame(['version' => null, 'commit' => null], $state['from']);
        self::assertStringContainsString('"version": "2.0.0"', $this->read('release.json'));
        self::assertFileExists($this->project.'/release-files.json');
        // Without an inventory the differing config default is kept as an override.
        self::assertStringEndsWith("parameters: { app.goal_events: { operator: {} } }\n", $this->read('config/goals.local.yaml'));
    }

    public function testSystemCheckExplainsReleaseAndGitRequirements(): void
    {
        $checks = array_column($this->updater()->preflight()['checks'], null, 'id');

        self::assertSame('ok', $checks['source']['status']);
        self::assertStringContainsString('Release ZIPs from Subschema-LLC/aggregate, branch master', $checks['source']['detail']);
        self::assertSame('ok', $checks['signing_key']['status']);
        self::assertSame('ok', $checks['release_metadata']['status']);
        self::assertStringContainsString('SQLite', $checks['database']['detail']);
        self::assertArrayNotHasKey('git', $checks);
        self::assertSame('dashboard', $checks['upload_limit']['scope']);

        unlink($this->project.'/config/release-signing.pub');
        unlink($this->project.'/release.json');
        $checks = array_column($this->updater()->preflight()['checks'], null, 'id');
        self::assertSame('error', $checks['signing_key']['status']);
        self::assertSame('warning', $checks['release_metadata']['status']);
        self::assertStringContainsString('stop its automatic deployments first', $checks['release_metadata']['detail']);
        self::assertContains($checks['signing_key']['detail'], $this->updater()->preflight()['problems']);
    }

    public function testInvalidRepositorySettingIsAProblem(): void
    {
        $preflight = $this->updater(settings: ['updates_repository' => 'not a repository'])->preflight();
        $checks = array_column($preflight['checks'], null, 'id');

        self::assertSame('error', $checks['source']['status']);
        self::assertStringContainsString('updates_repository must be a GitHub repository', $checks['source']['detail']);
        self::assertContains($checks['source']['detail'], $preflight['problems']);
    }

    public function testUploadedReleaseFilesAreVerifiedAndIdentifiedByExtension(): void
    {
        [$package, $manifest, $signature] = $this->package('2.0.0');
        $uploads = [];
        foreach (['aggregate-2.0.0 (1).zip' => $package, 'aggregate-release (1).json' => $manifest, 'aggregate-release.json (1).sig' => $signature] as $name => $source) {
            $temporary = $this->directory.'/php-upload-'.bin2hex(random_bytes(4));
            copy($source, $temporary);
            $uploads[$name] = $temporary;
        }

        $staged = $this->updater()->stageUpload($uploads);

        self::assertSame('2.0.0', $staged['version']);
        self::assertStringStartsWith($this->project.'/var/updates/uploads/', $staged['package']);
        self::assertFileExists($staged['manifest']);

        $tampered = $this->directory.'/tampered.zip';
        copy($package, $tampered);
        file_put_contents($tampered, 'x', FILE_APPEND);
        $copies = [];
        foreach (['a.zip' => $tampered, 'b.json' => $manifest, 'c.sig' => $signature] as $name => $source) {
            $copies[$name] = $this->directory.'/copy-'.$name;
            copy($source, $copies[$name]);
        }
        try {
            $this->updater()->stageUpload($copies);
            self::fail('A package that does not match its signed manifest must be refused.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('does not match its signed manifest', $e->getMessage());
        }
        self::assertCount(1, glob($this->project.'/var/updates/uploads/*'), 'Refused uploads are removed.');

        $this->expectExceptionMessage('Missing aggregate-release.json.sig');
        $this->updater()->stageUpload(['a.zip' => $package, 'b.json' => $manifest]);
    }

    public function testServerDatabasesRequireAConfirmedBackup(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'dbname' => 'aggregate', 'user' => 'app']);
        [$package, $manifest, $signature] = $this->package('2.0.0');

        $this->expectExceptionMessage('--database-backup-confirmed');
        $this->updater()->start(['package' => $package, 'manifest' => $manifest, 'signature' => $signature], $this->record(...));
    }

    public function testDowngradesAreRefused(): void
    {
        $this->put('release.json', $this->identity('3.0.0'));
        [$package, $manifest, $signature] = $this->package('2.0.0');

        $state = $this->updater()->start(['package' => $package, 'manifest' => $manifest, 'signature' => $signature], $this->record(...));

        self::assertSame('failed', $state['status']);
        self::assertStringContainsString('Downgrades are not supported', $state['error']);
    }

    public function testFingerprintIdentifiesTheDatabaseWithoutRevealingIt(): void
    {
        $fingerprint = $this->updater()->databaseFingerprint();

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $fingerprint);
        self::assertStringNotContainsString('data.db', $fingerprint);
        self::assertTrue($this->updater()->isSqlite());
    }

    public function testGitCheckoutUpdateMovesConfigEditsAndRollsBackToThePreviousCommit(): void
    {
        $this->connection->close();
        (new ReleasePackageInstaller($this->project, new LocalConfigOverrides($this->project)))->removeTree($this->project);
        $source = $this->directory.'/source';
        mkdir($source.'/config', 0775, true);
        mkdir($source.'/bin');
        mkdir($source.'/src');
        mkdir($source.'/public');
        $this->git(['init', '--quiet', '--initial-branch=master'], $source);
        file_put_contents($source.'/.gitignore', "/vendor/\n/var/\n/config/*.local.yaml\n/config/aggregate.yaml\n/.env.local\n");
        file_put_contents($source.'/bin/console', "<?php\nexit(0);\n");
        file_put_contents($source.'/src/App.php', '<?php // one');
        file_put_contents($source.'/public/index.php', '<?php');
        file_put_contents($source.'/config/goals.yaml', "parameters: { app.goal_events: { purchase: {} } }\n");
        $initial = $this->commitAll($source);
        $this->git(['clone', '--quiet', $source, $this->project], $this->directory);
        $this->git(['config', 'url.'.$source.'.insteadOf', ApplicationUpdateService::REPOSITORY_URL.'.git'], $this->project);
        foreach (['vendor', 'var'] as $directory) {
            mkdir($this->project.'/'.$directory);
        }
        file_put_contents($this->project.'/vendor/autoload.php', '<?php');
        file_put_contents($this->project.'/config/aggregate.yaml', "app_host: example.test\n");
        file_put_contents($this->project.'/config/goals.yaml', "parameters: { app.goal_events: { operator: {} } }\n");
        file_put_contents($source.'/src/App.php', '<?php // two');
        file_put_contents($source.'/config/goals.yaml', "parameters: { app.goal_events: { purchase: {}, lead: {} } }\n");
        $latest = $this->commitAll($source);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->project.'/var/data.db']);
        $this->connection->executeStatement('CREATE TABLE events (id INTEGER PRIMARY KEY)');
        $github = new MockHttpClient([
            new MockResponse(json_encode(['sha' => $latest]), ['http_code' => 200]),
            new MockResponse(json_encode(['status' => 'ahead', 'ahead_by' => 1, 'behind_by' => 0]), ['http_code' => 200]),
        ]);

        $state = $this->updater($github)->start([], $this->record(...));

        self::assertSame('git', $state['type']);
        self::assertSame('needs_attention', $state['status'], 'The fixture console cannot finish the update.');
        self::assertSame($latest, $this->git(['rev-parse', 'HEAD'], $this->project));
        self::assertSame('<?php // two', $this->read('src/App.php'));
        self::assertSame("parameters: { app.goal_events: { purchase: {}, lead: {} } }\n", $this->read('config/goals.yaml'));
        self::assertStringEndsWith("parameters: { app.goal_events: { operator: {} } }\n", $this->read('config/goals.local.yaml'));
        self::assertSame("app_host: example.test\n", $this->read('config/aggregate.yaml'));
        self::assertFileExists($this->project.'/'.$state['backup'].'/database.sqlite');
        self::assertTrue((new MaintenanceMode($this->project))->status()['active']);

        $rolledBack = $this->updater()->rollback(false, $this->record(...));

        self::assertSame('rolled_back', $rolledBack['status']);
        self::assertSame($initial, $this->git(['rev-parse', 'HEAD'], $this->project));
        self::assertSame('<?php // one', $this->read('src/App.php'));
        // The shipped default is restored; the operator's edits stay in their override.
        self::assertSame("parameters: { app.goal_events: { purchase: {} } }\n", $this->read('config/goals.yaml'));
        self::assertFileExists($this->project.'/config/goals.local.yaml');
        self::assertSame('', $this->git(['status', '--porcelain'], $this->project));
        self::assertNull((new MaintenanceMode($this->project))->status());
    }

    private function commitAll(string $repository): string
    {
        $this->git(['add', '--all'], $repository);
        $this->git(['commit', '--quiet', '--message=fixture'], $repository);

        return $this->git(['rev-parse', 'HEAD'], $repository);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, string $repository): string
    {
        $process = new Process(['git', '-c', 'user.name=Update Test', '-c', 'user.email=updates@example.test', '-c', 'commit.gpgSign=false', ...$arguments], $repository, timeout: 15);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function record(string $message, string $level): void
    {
        $this->messages[] = [$level, $message];
    }

    /** @param array<string, mixed> $settings */
    private function updater(?MockHttpClient $github = null, array $settings = []): ApplicationUpdater
    {
        $config = $this->createStub(AggregateConfigLoader::class);
        $config->method('all')->willReturn($settings);
        $features = new FeatureFlags($config);
        $settings = new UpdateSettings($config);
        $cache = new ArrayAdapter();
        $clock = new MockClock();
        $installed = new InstalledRelease($this->project);
        $releases = new ReleaseUpdateService($installed, new MockHttpClient([]), $cache, $clock, $settings, $features);
        $overrides = new LocalConfigOverrides($this->project);
        $journal = new UpdateJournal($this->project);
        $maintenance = new MaintenanceMode($this->project);
        $verifier = new ReleasePackageVerifier($config, $settings, $this->project, $features);
        $updates = new ApplicationUpdateService($this->project, $github ?? new MockHttpClient([]), $cache, $clock, $features, '', $settings, $releases, $overrides);
        $toolchain = new Toolchain($this->project);

        return new ApplicationUpdater(
            $this->project, 'prod', false,
            $journal,
            $maintenance,
            new ReleasePackageInstaller($this->project, $overrides),
            $verifier,
            $releases,
            $updates,
            $installed,
            $features,
            $this->connection,
            new SystemCheck($this->project, $updates, $settings, $installed, $verifier, $journal, $maintenance, $features, $this->connection, $toolchain),
            $toolchain,
        );
    }

    /** @return array<string, string> */
    private function release(string $version): array
    {
        $files = [
            'LICENSE' => 'license',
            'composer.json' => '{}',
            'composer.lock' => '{}',
            'vendor/autoload.php' => '<?php',
            'vendor/autoload_runtime.php' => '<?php',
            'public/index.php' => '<?php // '.$version,
            // Stands in for a release whose console cannot continue the update.
            'bin/console' => "<?php\nexit(0);\n",
            'src/App.php' => '<?php // '.$version,
            'config/goals.yaml' => "parameters: { app.goal_events: { purchase: {} } }\n",
            '.env' => "APP_ENV=prod\n",
            'release.json' => $this->identity($version),
        ];
        if ($version !== '1.0.0') {
            $files['config/goals.yaml'] = "parameters: { app.goal_events: { purchase: {}, lead: {} } }\n";
            $files['src/Added.php'] = '<?php // added';
        }
        ksort($files);
        $files['release-files.json'] = json_encode([
            'schema' => 1, 'version' => $version,
            'files' => array_map(static fn (string $content): string => hash('sha256', $content), $files),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $files;
    }

    private function identity(string $version): string
    {
        return json_encode([
            'schema' => 1,
            'version' => $version,
            'repository' => ApplicationUpdateService::REPOSITORY,
            'branch' => 'master',
            'commit' => str_repeat($version === '1.0.0' ? 'a' : 'b', 40),
            'built_at' => '2026-09-14T12:00:00Z',
            'requirements' => ['php' => '>=8.2', 'extensions' => []],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function package(string $version): array
    {
        $package = $this->directory.'/aggregate-'.$version.'.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($package, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($this->release($version) as $path => $content) {
            $zip->addFromString($path, $content);
            $zip->setExternalAttributesName($path, \ZipArchive::OPSYS_UNIX, (0100000 | ($path === 'bin/console' ? 0755 : 0644)) << 16);
        }
        $zip->close();
        $manifest = json_decode($this->identity($version), true);
        $manifest['package'] = ['filename' => basename($package), 'sha256' => hash_file('sha256', $package), 'size' => filesize($package)];
        $manifestPath = $this->directory.'/aggregate-release.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $signaturePath = $manifestPath.'.sig';
        file_put_contents($signaturePath, base64_encode(sodium_crypto_sign_detached((string) file_get_contents($manifestPath), $this->secretKey)));

        return [$package, $manifestPath, $signaturePath];
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

    /** @return array<string, string> */
    private function snapshot(string $except = "\0"): array
    {
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $relative = substr($item->getPathname(), strlen($this->project) + 1);
            if (!str_starts_with($relative, $except)) {
                $files[$relative] = $item->isDir() ? 'directory' : file_get_contents($item->getPathname()).'|'.decoct(fileperms($item->getPathname()) & 0777);
            }
        }
        ksort($files);

        return $files;
    }
}
