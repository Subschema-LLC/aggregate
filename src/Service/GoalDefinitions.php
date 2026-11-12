<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Cluster\ServerRole;
use App\Service\Operations\ConfigurationChanges;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The approved conversion goals (`app.goal_events`): config/goals.yaml as
 * shipped, replaced as a whole by config/goals.local.yaml when that file
 * defines them.
 *
 * They are read when used, not compiled into the service container, so a
 * change saved on the Goals page, by app:config or by the configuration pull
 * on another server applies to the next request without clearing the cache.
 * Saving writes config/goals.local.yaml only: application updates replace
 * goals.yaml and keep the local file.
 */
class GoalDefinitions
{
    public const PARAMETER = 'app.goal_events';
    public const SHIPPED_FILE = 'config/goals.yaml';
    public const LOCAL_FILE = 'config/goals.local.yaml';
    public const MAX_GOALS = 200;
    public const MAX_LABEL_LENGTH = 191;
    public const NAME_RULE = 'A goal code starts with a letter and has at most 100 letters, digits, dots, colons, hyphens and underscores, such as purchase or newsletter_signup.';

    private const LOCAL_HEADER = <<<'YAML'
        # Conversion goals for this installation. They replace the defaults in
        # config/goals.yaml as a whole. The Goals page (Data model > Goals) and
        # "php bin/console app:config set goals.goals ..." write this file; you
        # can also edit it by hand. Changes apply without clearing the cache.

        YAML;

    /** @var array{key: string, goals: array<string, array{label: string, enabled: bool, anonymous: bool}>, source: string}|null */
    private ?array $loaded = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ?ParsedYamlCache $cache = null,
    ) {
    }

    /**
     * The goals in effect, validated, by code.
     *
     * @return array<string, array{label: string, enabled: bool, anonymous: bool}>
     *
     * @throws \InvalidArgumentException when the goals in effect are not valid
     * @throws \RuntimeException         when a goals file cannot be read
     */
    public function all(): array
    {
        return $this->load()['goals'];
    }

    /**
     * Where the goals in effect come from: the local file, or the shipped defaults.
     *
     * @throws \InvalidArgumentException when the goals in effect are not valid
     * @throws \RuntimeException         when a goals file cannot be read
     */
    public function source(): string
    {
        return $this->load()['source'];
    }

    /**
     * Checks goals the way collection uses them and returns them normalized.
     *
     * @param mixed $definitions code => {label, enabled, anonymous}
     *
     * @return array<string, array{label: string, enabled: bool, anonymous: bool}>
     *
     * @throws \InvalidArgumentException naming the first goal that is not valid
     */
    public static function validate(mixed $definitions): array
    {
        if ($definitions === null) {
            return [];
        }
        if (!is_array($definitions) || ($definitions !== [] && array_is_list($definitions))) {
            throw new \InvalidArgumentException('Goals are a mapping of goal codes to their label, enabled and anonymous options.');
        }
        if (count($definitions) > self::MAX_GOALS) {
            throw new \InvalidArgumentException(sprintf('At most %d goals can be defined.', self::MAX_GOALS));
        }

        $validated = [];
        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || trim($name) !== $name || !PrivacySanitizer::isSafeEventName($name)) {
                throw new \InvalidArgumentException('Configured goal names must use the safe event-name format. '.self::NAME_RULE);
            }
            if (!is_array($definition)) {
                throw new \InvalidArgumentException(sprintf('Goal definition "%s" must be a mapping.', $name));
            }
            $unexpectedKeys = array_diff(array_keys($definition), ['label', 'enabled', 'anonymous']);
            if ($unexpectedKeys !== []) {
                throw new \InvalidArgumentException(sprintf('Goal definition "%s" contains unsupported options.', $name));
            }
            $label = $definition['label'] ?? null;
            if (!is_string($label) || trim($label) === '' || strlen($label) > self::MAX_LABEL_LENGTH) {
                throw new \InvalidArgumentException(sprintf('Goal definition "%s" requires a non-empty label.', $name));
            }
            if (!array_key_exists('enabled', $definition) || !is_bool($definition['enabled'])) {
                throw new \InvalidArgumentException(sprintf('Goal definition "%s" requires a boolean enabled option.', $name));
            }
            if (!array_key_exists('anonymous', $definition) || !is_bool($definition['anonymous'])) {
                throw new \InvalidArgumentException(sprintf('Goal definition "%s" requires a boolean anonymous option.', $name));
            }
            $validated[$name] = [
                'label' => trim($label),
                'enabled' => $definition['enabled'],
                'anonymous' => $definition['anonymous'],
            ];
        }

        return $validated;
    }

    /**
     * Validates the goals and saves them to config/goals.local.yaml, keeping
     * any other parameters in that file. Saving the goals in effect again
     * changes nothing.
     *
     * @param mixed $definitions code => {label, enabled, anonymous}
     *
     * @return array<string, array{label: string, enabled: bool, anonymous: bool}> what was saved
     *
     * @throws \InvalidArgumentException when a goal is not valid; nothing is saved
     * @throws \RuntimeException         when the file cannot be written
     */
    public function save(mixed $definitions): array
    {
        $goals = self::validate($definitions);
        ServerRole::forProject($this->projectDir)->assertWritesConfiguration();

        $path = $this->projectDir.'/'.self::LOCAL_FILE;
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException(self::LOCAL_FILE.' could not be opened for writing. Make it writable by the web server\'s user.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException(self::LOCAL_FILE.' could not be locked for writing.');
            }
            $original = (string) stream_get_contents($handle);
            $document = self::parseDocument($original, self::LOCAL_FILE);
            $before = is_array($document['parameters'][self::PARAMETER] ?? null) ? $document['parameters'][self::PARAMETER] : null;
            if ($before === $goals) {
                return $goals;
            }
            $document['parameters'] = is_array($document['parameters'] ?? null) ? $document['parameters'] : [];
            $document['parameters'][self::PARAMETER] = $goals;
            $yaml = self::LOCAL_HEADER.Yaml::dump($document, 4, 4);
            if (!rewind($handle) || !ftruncate($handle, 0) || fwrite($handle, $yaml) !== strlen($yaml) || !fflush($handle)) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, $original);
                throw new \RuntimeException(self::LOCAL_FILE.' could not be written.');
            }
            ConfigurationChanges::recordMapping(self::LOCAL_FILE, $before ?? [], $goals);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->loaded = null;

        return $goals;
    }

    /**
     * @return array{key: string, goals: array<string, array{label: string, enabled: bool, anonymous: bool}>, source: string}
     */
    private function load(): array
    {
        $local = $this->read(self::LOCAL_FILE);
        $shipped = $this->read(self::SHIPPED_FILE);
        $key = hash('xxh128', $local."\0".$shipped);
        if ($this->loaded !== null && $this->loaded['key'] === $key) {
            return $this->loaded;
        }

        $localGoals = $this->parameter($local, self::LOCAL_FILE);
        $useLocal = $localGoals !== null;
        $definitions = $useLocal ? $localGoals : ($this->parameter($shipped, self::SHIPPED_FILE) ?? []);

        return $this->loaded = ['key' => $key, 'goals' => self::validate($definitions), 'source' => $useLocal ? self::LOCAL_FILE : self::SHIPPED_FILE];
    }

    private function read(string $file): string
    {
        $path = $this->projectDir.'/'.$file;
        if (!is_file($path)) {
            return '';
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException($file.' could not be read.');
        }

        return $contents;
    }

    /** The goals a file defines, or null when it defines none. */
    private function parameter(string $yaml, string $file): ?array
    {
        if (trim($yaml) === '') {
            return null;
        }
        $document = $this->cache !== null
            ? $this->cache->remember('goals-'.basename($file, '.yaml'), $yaml, static fn (string $yaml): array => self::parseDocument($yaml, $file))
            : self::parseDocument($yaml, $file);
        $parameters = $document['parameters'] ?? null;
        if (!is_array($parameters) || !array_key_exists(self::PARAMETER, $parameters)) {
            return null;
        }
        $goals = $parameters[self::PARAMETER];

        return is_array($goals) ? $goals : self::validate($goals);
    }

    /** @return array<mixed> */
    private static function parseDocument(string $yaml, string $file): array
    {
        if (trim((string) preg_replace('/^\s*#.*$/m', '', $yaml)) === '') {
            return [];
        }
        try {
            $document = Yaml::parse($yaml);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException($file.' is not valid YAML: '.$e->getMessage());
        }
        if (!is_array($document) || ($document !== [] && array_is_list($document))) {
            throw new \InvalidArgumentException($file.' must be a YAML mapping with a parameters section.');
        }

        return $document;
    }
}
