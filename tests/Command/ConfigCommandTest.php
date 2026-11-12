<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ConfigCommand;
use App\Service\Cluster\ServerRole;
use App\Service\Settings\SettingsSection;
use App\Service\Settings\SettingsSections;
use App\Service\Settings\SettingsWithScopeOverrides;
use App\Service\Settings\SettingsWithSecrets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:config's own behavior, with a stand-in section: reading values as
 * YAML, checking without saving, keeping secrets off the command line, and
 * refusing to change configuration where the server's role does not.
 */
final class ConfigCommandTest extends TestCase
{
    private const DATABASE = 'mysql://aggregate:secret@10.0.0.5:3306/aggregate?serverVersion=8.0.0';

    public function testSectionsAreListedWithTheirPages(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('example', $tester->getDisplay());
        self::assertStringContainsString('Administration > Example', $tester->getDisplay());
        self::assertStringContainsString('2 + 1 secret', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['action' => 'list', '--json' => true]));
        $list = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['name' => 'example', 'page' => 'Administration > Example', 'target' => 'scope', 'keys' => ['color', 'limit'], 'secrets' => ['token']], $list['sections'][0]);
    }

    public function testValuesAreReadAsYamlAndTextYamlCannotReadIsKept(): void
    {
        $section = new ExampleSection();
        $tester = $this->tester($section);

        self::assertSame(0, $tester->execute(['action' => 'set', 'name' => 'example.limit', 'value' => '250']));
        self::assertSame(0, $tester->execute(['action' => 'set', 'name' => 'example.color', 'value' => '#336699']));
        self::assertSame(['limit' => 250, 'color' => '#336699'], $section->applied);
        self::assertStringContainsString('Saved.', $tester->getDisplay());

        self::assertSame([1, 2], ConfigCommand::parse('[1, 2]'));
        self::assertSame(['a' => true], ConfigCommand::parse('{a: true}'));
        self::assertNull(ConfigCommand::parse('null'));
        self::assertSame('plain text: with a colon', ConfigCommand::parse('"plain text: with a colon"'));
    }

    public function testCheckValidatesWithoutSavingAndErrorsFail(): void
    {
        $section = new ExampleSection();
        $tester = $this->tester($section);

        self::assertSame(0, $tester->execute(['action' => 'set', 'name' => 'example.limit', 'value' => '5', '--check' => true]));
        self::assertSame([], $section->applied, '--check saves nothing.');
        self::assertSame(1, $tester->execute(['action' => 'set', 'name' => 'example.limit', 'value' => '-1']));
        self::assertStringContainsString('limit must be at least 1', $tester->getDisplay());
        self::assertSame(1, $tester->execute(['action' => 'set', 'name' => 'example', 'value' => '5']));
        self::assertStringContainsString('Name the setting', $tester->getDisplay());
        self::assertSame(1, $tester->execute(['action' => 'set', 'name' => 'missing.limit', 'value' => '5']));
        self::assertStringContainsString('There are no "missing" settings', $tester->getDisplay());
    }

    public function testGetExportAndImportRoundTrip(): void
    {
        $section = new ExampleSection();
        $tester = $this->tester($section);

        self::assertSame(0, $tester->execute(['action' => 'get', 'name' => 'example', '--json' => true]));
        $shown = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['color' => '#000000', 'limit' => 100], $shown['values']);
        self::assertSame(['limit'], $shown['environment_overrides']);
        self::assertSame(['token' => false], $shown['secrets_set']);

        self::assertSame(0, $tester->execute(['action' => 'export', 'name' => 'example']));
        $file = tempnam(sys_get_temp_dir(), 'aggregate-config-');
        file_put_contents($file, $tester->getDisplay());
        self::assertSame(0, $tester->execute(['action' => 'import', 'name' => 'example', '--file' => $file]));
        unlink($file);
        self::assertSame(['color' => '#000000', 'limit' => 100], $section->applied);

        $stdin = fopen('php://memory', 'r+');
        fwrite($stdin, "- not\n- a mapping\n");
        rewind($stdin);
        $command = $this->command($section);
        $command->setInputStream($stdin);
        self::assertSame(1, (new CommandTester($command))->execute(['action' => 'import', 'name' => 'example', '--stdin' => true]));
    }

    public function testSecretsComeOnlyFromStandardInputOrAFileAndAreNeverPrinted(): void
    {
        $section = new ExampleSection();
        $command = $this->command($section);
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute(['action' => 'secret', 'name' => 'example.token', 'value' => 'on-the-command-line']));
        self::assertStringContainsString('never taken from the command line', $tester->getDisplay());
        self::assertNull($section->secret);

        $stdin = fopen('php://memory', 'r+');
        fwrite($stdin, "s3cr3t-value\n");
        rewind($stdin);
        $command->setInputStream($stdin);
        self::assertSame(0, $tester->execute(['action' => 'secret', 'name' => 'example.token', '--stdin' => true]));
        self::assertSame('s3cr3t-value', $section->secret, 'The trailing newline is dropped.');
        self::assertStringNotContainsString('s3cr3t-value', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['action' => 'secret', 'name' => 'example.token', '--remove' => true]));
        self::assertSame('removed', $section->secret);
    }

    public function testScopesAndSitesGoOnlyToSectionsThatTakeThem(): void
    {
        $section = new ExampleSection();
        $tester = $this->tester($section);

        self::assertSame(0, $tester->execute(['action' => 'set', 'name' => 'example.limit', 'value' => '7', '--scope' => 'group:shop']));
        self::assertSame('group:shop', $section->target);
        self::assertSame(1, $tester->execute(['action' => 'set', 'name' => 'example.limit', 'value' => '7', '--site' => 'abc']));
        self::assertStringContainsString('do not belong to one website', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['action' => 'unset', 'name' => 'example.limit', '--scope' => 'group:shop']));
        self::assertSame(['limit'], $section->unset);
        self::assertSame(1, $tester->execute(['action' => 'unset', 'name' => 'example.limit']));
    }

    public function testCollectionAndWorkerServersShowButNeverChangeSettings(): void
    {
        $section = new ExampleSection();
        $tester = $this->tester($section, ServerRole::of('collection', self::DATABASE, 'https://admin.example.com'));

        self::assertSame(0, $tester->execute(['action' => 'get', 'name' => 'example']));
        self::assertSame(1, $tester->execute(['action' => 'set', 'name' => 'example.limit', 'value' => '7']));
        self::assertStringContainsString('its configuration is changed on the administration server (https://admin.example.com)', preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame([], $section->applied);
    }

    private function tester(?ExampleSection $section = null, ?ServerRole $role = null): CommandTester
    {
        return new CommandTester($this->command($section ?? new ExampleSection(), $role));
    }

    private function command(ExampleSection $section, ?ServerRole $role = null): ConfigCommand
    {
        return new ConfigCommand(new SettingsSections([$section]), $role ?? ServerRole::of('all'));
    }
}

/** A section with one validated number, one color, one secret and scope overrides. */
final class ExampleSection implements SettingsSection, SettingsWithSecrets, SettingsWithScopeOverrides
{
    /** @var array<string, mixed> */
    public array $applied = [];
    public ?string $target = null;
    public ?string $secret = null;
    /** @var list<string> */
    public array $unset = [];

    public function name(): string
    {
        return 'example';
    }

    public function page(): string
    {
        return 'Administration > Example';
    }

    public function target(): ?string
    {
        return self::TARGET_SCOPE;
    }

    public function keys(): array
    {
        return ['color', 'limit'];
    }

    public function values(?string $target = null): array
    {
        return ['color' => '#000000', 'limit' => 100];
    }

    public function environmentOverrides(?string $target = null): array
    {
        return ['limit'];
    }

    public function apply(array $changes, ?string $target = null, bool $check = false, bool $confirmed = false): array
    {
        if (isset($changes['limit']) && $changes['limit'] < 1) {
            throw new \InvalidArgumentException('limit must be at least 1.');
        }
        if (!$check) {
            $this->applied = [...$this->applied, ...$changes];
            $this->target = $target;
        }

        return ['A note.', 'Saved.'];
    }

    public function secrets(): array
    {
        return ['token' => 'An API token'];
    }

    public function secretsSet(?string $target = null): array
    {
        return ['token' => $this->secret !== null];
    }

    public function setSecret(string $name, #[\SensitiveParameter] ?string $value, ?string $target = null, bool $check = false): string
    {
        $this->secret = $value ?? 'removed';

        return 'Stored.';
    }

    public function unset(array $keys, string $target, bool $check = false): string
    {
        $this->unset = $keys;

        return 'Removed.';
    }
}
