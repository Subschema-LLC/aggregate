<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Cluster\ServerRole;
use App\Service\DocumentationLinks;
use App\Service\Settings\SettingsSection;
use App\Service\Settings\SettingsSections;
use App\Service\Settings\SettingsWithScopeOverrides;
use App\Service\Settings\SettingsWithSecrets;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Every setting a dashboard page changes, from the command line: shown,
 * set, exported and imported through the page's own checks, and recorded in
 * the audit trail as the page records them. Secrets are read from standard
 * input or a file, never from the command line, and never printed.
 */
#[AsCommand(
    name: 'app:config',
    description: 'Show or change any setting a dashboard page changes, with the same checks: list, get, set, unset, export, import or secret',
)]
final class ConfigCommand extends Command
{
    public const ACTIONS = ['list', 'get', 'set', 'unset', 'export', 'import', 'secret'];
    public const WRITING = ['set', 'unset', 'import', 'secret'];
    private const MAX_INPUT_BYTES = 1_048_576;

    /** @var resource|null standard input, replaceable in tests */
    private $stdin = null;

    public function __construct(
        private readonly SettingsSections $sections,
        private readonly ServerRole $role,
        private readonly ?DocumentationLinks $documentation = null,
    ) {
        parent::__construct();
    }

    /** @param resource $stream */
    public function setInputStream($stream): void
    {
        $this->stdin = $stream;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, implode(', ', self::ACTIONS), 'list')
            ->addArgument('name', InputArgument::OPTIONAL, 'A section, such as branding, or a setting, such as branding.brand_name')
            ->addArgument('value', InputArgument::OPTIONAL, 'With set: the value, read as YAML (true, 100, [a, b], {key: value}); text that YAML cannot read is taken as it is')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'For collection and data-model: website:<script id>, group:<id> or deployment')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'For consent-manager, tag-manager and standalone-consent: the website\'s script id')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'With import or secret: read from this file')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'With set, import or secret: read the value from standard input')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'With secret: remove the secret')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Check the values as the page would, and save nothing')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Confirm a change the page asks to confirm, such as copying private views')
            ->addOption('json', null, InputOption::VALUE_NONE, 'With list or get: print JSON')
            ->setHelp(<<<HELP
                Every setting a dashboard page changes, in the same YAML keys, checked and saved the way the
                page checks and saves it. Environment variables still take precedence, as on the pages.

                  php bin/console app:config                          the sections and their pages
                  php bin/console app:config get branding             the values in effect
                  php bin/console app:config set branding.brand_name "Acme Analytics"
                  php bin/console app:config set collection.bot_traffic drop --scope=group:shop
                  php bin/console app:config unset collection.bot_traffic --scope=group:shop
                  php bin/console app:config export data-lifecycle > lifecycle.yaml
                  php bin/console app:config import data-lifecycle --file=lifecycle.yaml --check
                  php bin/console app:config secret email.postmark_server_token --stdin < token.txt

                A value is read as YAML, so quote text YAML would read otherwise ("yes", "1.0").
                Secrets come from standard input or a file and are never printed.

                {$this->guide()}
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = $output instanceof ConsoleOutputInterface ? new SymfonyStyle($input, $output->getErrorOutput()) : $io;
        $action = (string) $input->getArgument('action');
        try {
            if (!in_array($action, self::ACTIONS, true)) {
                throw new \InvalidArgumentException('Use one of: '.implode(', ', self::ACTIONS).'.');
            }
            if ($action === 'list') {
                return $this->list($io, $output, (bool) $input->getOption('json'));
            }
            [$section, $key] = $this->target((string) $input->getArgument('name'));
            $target = $this->targetFor($section, $input);
            if (in_array($action, self::WRITING, true) && !$input->getOption('check')) {
                $this->role->assertWritesConfiguration();
            }

            return match ($action) {
                'get' => $this->get($io, $output, $section, $key, $target, (bool) $input->getOption('json')),
                'export' => $this->export($output, $section, $key, $target),
                'set' => $this->report($io, $section->apply(
                    [$this->requireKey($section, $key) => $this->value($input)],
                    $target,
                    (bool) $input->getOption('check'),
                    (bool) $input->getOption('confirm'),
                )),
                'import' => $this->report($io, $section->apply(
                    $this->mapping($this->read($input, true), $key),
                    $target,
                    (bool) $input->getOption('check'),
                    (bool) $input->getOption('confirm'),
                )),
                'unset' => $this->unset($io, $section, $key, $target, (bool) $input->getOption('check')),
                'secret' => $this->secret($io, $input, $section, $key, $target),
            };
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $errors->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function list(SymfonyStyle $io, OutputInterface $output, bool $json): int
    {
        $rows = [];
        foreach ($this->sections->all() as $name => $section) {
            $rows[] = [
                'name' => $name,
                'page' => $section->page(),
                'target' => $section->target(),
                'keys' => $section->keys(),
                'secrets' => $section instanceof SettingsWithSecrets ? array_keys($section->secrets()) : [],
            ];
        }
        if ($json) {
            $output->writeln(json_encode(['sections' => $rows], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        $io->table(['Section', 'Dashboard page', 'For', 'Settings'], array_map(static fn (array $row): array => [
            $row['name'],
            $row['page'],
            match ($row['target']) {
                SettingsSection::TARGET_SCOPE => 'installation, --scope',
                SettingsSection::TARGET_SITE => '--site',
                default => 'installation',
            },
            count($row['keys']).($row['secrets'] !== [] ? ' + '.count($row['secrets']).' secret'.(count($row['secrets']) === 1 ? '' : 's') : ''),
        ], $rows));
        $io->writeln('Show a section with: php bin/console app:config get <section>');

        return Command::SUCCESS;
    }

    private function get(SymfonyStyle $io, OutputInterface $output, SettingsSection $section, ?string $key, ?string $target, bool $json): int
    {
        $values = $this->selected($section, $key, $target);
        $overridden = array_values(array_intersect(array_keys($values), $section->environmentOverrides($target)));
        $secrets = $key === null && $section instanceof SettingsWithSecrets ? $section->secretsSet($target) : [];
        if ($json) {
            $output->writeln(json_encode(array_filter([
                'section' => $section->name(),
                'values' => $values,
                'environment_overrides' => $overridden,
                'secrets_set' => $secrets === [] ? null : $secrets,
            ], static fn (mixed $value): bool => $value !== null), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }
        $output->write(Yaml::dump($values, 6, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
        if ($overridden !== []) {
            $io->note('Set by an environment variable, which takes precedence: '.implode(', ', $overridden).'.');
        }
        foreach ($secrets as $name => $set) {
            $io->writeln(sprintf('Secret %s: %s', $name, $set ? 'set' : 'not set'));
        }

        return Command::SUCCESS;
    }

    private function export(OutputInterface $output, SettingsSection $section, ?string $key, ?string $target): int
    {
        $output->writeln('# '.$section->name().' settings ('.$section->page().'). Import with: php bin/console app:config import '.$section->name().' --file=<this file>');
        $output->write(Yaml::dump($this->selected($section, $key, $target), 6, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

        return Command::SUCCESS;
    }

    private function unset(SymfonyStyle $io, SettingsSection $section, ?string $key, ?string $target, bool $check): int
    {
        if (!$section instanceof SettingsWithScopeOverrides) {
            throw new \InvalidArgumentException(sprintf('The %s settings have no website or group values to remove. Set a new value with app:config set.', $section->name()));
        }
        if ($target === null) {
            throw new \InvalidArgumentException('Name the website or group with --scope.');
        }

        return $this->report($io, [$section->unset([$this->requireKey($section, $key)], $target, $check)]);
    }

    private function secret(SymfonyStyle $io, InputInterface $input, SettingsSection $section, ?string $key, ?string $target): int
    {
        if (!$section instanceof SettingsWithSecrets) {
            throw new \InvalidArgumentException(sprintf('The %s settings have no secrets.', $section->name()));
        }
        if ($input->getArgument('value') !== null) {
            throw new \InvalidArgumentException('A secret is never taken from the command line, where it would stay in the shell history: use --stdin or --file.');
        }
        if ($key === null || !array_key_exists($key, $section->secrets())) {
            throw new \InvalidArgumentException(sprintf('Name the secret: %s.', implode(', ', array_map(static fn (string $name): string => $section->name().'.'.$name, array_keys($section->secrets())))));
        }
        $value = $input->getOption('remove') ? null : $this->read($input, false);

        return $this->report($io, [$section->setSecret($key, $value, $target, (bool) $input->getOption('check'))]);
    }

    /** @param list<string> $notes */
    private function report(SymfonyStyle $io, array $notes): int
    {
        $last = array_pop($notes);
        foreach ($notes as $note) {
            $io->note($note);
        }
        if ($last !== null) {
            $io->success($last);
        }

        return Command::SUCCESS;
    }

    /** @return array{0: SettingsSection, 1: ?string} */
    private function target(string $name): array
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Name a section, such as branding, or a setting, such as branding.brand_name. php bin/console app:config lists them.');
        }
        [$sectionName, $key] = array_pad(explode('.', $name, 2), 2, null);

        return [$this->sections->get((string) $sectionName), $key !== null && $key !== '' ? $key : null];
    }

    private function targetFor(SettingsSection $section, InputInterface $input): ?string
    {
        $scope = $input->getOption('scope');
        $site = $input->getOption('site');
        if ($scope !== null && $site !== null) {
            throw new \InvalidArgumentException('Use --scope or --site, not both.');
        }
        if ($scope !== null && $section->target() !== SettingsSection::TARGET_SCOPE) {
            throw new \InvalidArgumentException(sprintf('The %s settings do not vary by website group; leave out --scope.', $section->name()));
        }
        if ($site !== null && $section->target() !== SettingsSection::TARGET_SITE) {
            throw new \InvalidArgumentException(sprintf('The %s settings do not belong to one website; leave out --site.', $section->name()));
        }
        $target = $scope ?? $site;

        return is_string($target) && trim($target) !== '' ? trim($target) : null;
    }

    private function requireKey(SettingsSection $section, ?string $key): string
    {
        if ($key === null) {
            throw new \InvalidArgumentException(sprintf('Name the setting, such as %s.%s. The %s settings are: %s.', $section->name(), $section->keys()[0] ?? 'key', $section->name(), implode(', ', $section->keys())));
        }

        return $key;
    }

    /** @return array<string, mixed> */
    private function selected(SettingsSection $section, ?string $key, ?string $target): array
    {
        $values = $section->values($target);
        if ($key === null) {
            return $values;
        }
        if (!array_key_exists($key, $values)) {
            throw new \InvalidArgumentException(sprintf('%s is not one of the %s settings, which are: %s.', $key, $section->name(), implode(', ', $section->keys())));
        }

        return [$key => $values[$key]];
    }

    private function value(InputInterface $input): mixed
    {
        if ($input->getOption('stdin')) {
            return self::parse($this->read($input, false));
        }
        $value = $input->getArgument('value');
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Give the value after the setting\'s name, or use --stdin.');
        }

        return self::parse($value);
    }

    /**
     * A value as YAML reads it; text that YAML cannot read, or reads as
     * nothing although it is not empty (a color such as #336699 starts a
     * comment), is taken as it is.
     */
    public static function parse(string $raw): mixed
    {
        try {
            $value = Yaml::parse($raw);
        } catch (ParseException) {
            return $raw;
        }
        if ($value === null && !in_array(strtolower(trim($raw)), ['', '~', 'null'], true)) {
            return $raw;
        }

        return $value;
    }

    /**
     * The settings in an imported YAML mapping, or the one setting named.
     *
     * @return array<string, mixed>
     */
    private function mapping(string $yaml, ?string $key): array
    {
        try {
            $values = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new \InvalidArgumentException('The YAML could not be read: '.$e->getMessage());
        }
        if ($key !== null) {
            return [$key => $values];
        }
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new \InvalidArgumentException('Import a YAML mapping of settings, as app:config export prints it.');
        }

        return $values;
    }

    private function read(InputInterface $input, bool $allowArgument): string
    {
        $file = $input->getOption('file');
        if (is_string($file) && $file !== '') {
            if (!is_file($file) || !is_readable($file)) {
                throw new \InvalidArgumentException($file.' cannot be read.');
            }
            $contents = file_get_contents($file, false, null, 0, self::MAX_INPUT_BYTES + 1);
        } elseif ($input->getOption('stdin') || !$allowArgument || $input->getArgument('value') === null) {
            $stream = $this->stdin ?? \STDIN;
            $contents = stream_get_contents($stream, self::MAX_INPUT_BYTES + 1);
        } else {
            $contents = (string) $input->getArgument('value');
        }
        if (!is_string($contents)) {
            throw new \RuntimeException('Nothing could be read.');
        }
        if (strlen($contents) > self::MAX_INPUT_BYTES) {
            throw new \InvalidArgumentException('The input is larger than 1 MiB.');
        }

        return rtrim($contents, "\r\n");
    }

    private function guide(): string
    {
        return $this->documentation?->reference('configuration.command-line') ?? DocumentationLinks::file('configuration.command-line');
    }
}
