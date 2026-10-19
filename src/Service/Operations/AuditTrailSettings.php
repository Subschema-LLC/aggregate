<?php

declare(strict_types=1);

namespace App\Service\Operations;

use App\Service\AggregateConfigLoader;

/**
 * What the audit trail records, by area: a YAML mapping of area => true or
 * false under audit_trail_areas, edited on the Audit trail page or in YAML.
 * Each area is the category of its entries. An area missing from YAML takes
 * its default; there is no environment variable, as for feature flags.
 *
 * Changes to these switches are always recorded, whatever they are set to, so
 * turning auditing off leaves a trace. If the YAML is invalid, every area is
 * recorded as by default rather than silently recording less.
 */
class AuditTrailSettings
{
    public const KEY = 'audit_trail_areas';

    public const SETTINGS = 'settings';
    public const USERS = 'users';
    public const WEBSITES = 'websites';
    public const INTEGRATIONS = 'integrations';
    public const UPDATES = 'updates';
    public const OPERATIONS = 'operations';
    public const SIGN_INS = 'sign_ins';
    /** Background job runs, recorded by ProcessingTasks under the category AuditTrail::CATEGORY_TASK. */
    public const TASKS = AuditTrail::CATEGORY_TASK;

    /** @var array<string, array{label: string, description: string, default: bool}> */
    public const AREAS = [
        self::SETTINGS => [
            'label' => 'Settings changes',
            'description' => 'Saving a settings page: collection, BI disclosure, data model, glossary, page groups, reporting timezone, branding, general settings, consent and tag manager, organization traffic, feature flags, data lifecycle and these audit switches.',
            'default' => true,
        ],
        self::USERS => [
            'label' => 'Users',
            'description' => 'Creating, changing and deleting users, their access and permission groups, profile changes and passwords, from the dashboard, the installer or the command line.',
            'default' => true,
        ],
        self::WEBSITES => [
            'label' => 'Websites and groups',
            'description' => 'Adding and removing websites, their domain rules, and creating, renaming, deleting and assigning website groups.',
            'default' => true,
        ],
        self::INTEGRATIONS => [
            'label' => 'Integrations',
            'description' => 'BigQuery and reporting database connections, keys, Google sign-in, connection tests, and the views and schedules they copy.',
            'default' => true,
        ],
        self::UPDATES => [
            'label' => 'Updates',
            'description' => 'Checking for, uploading and installing updates, rollbacks, recorded deployments, the update method and source, and maintenance mode.',
            'default' => true,
        ],
        self::OPERATIONS => [
            'label' => 'Manual runs',
            'description' => 'Sync now, running the scheduled jobs, publishing columns and regenerating reporting views, glossary sync, building browser scripts and retrying failed tracking.',
            'default' => true,
        ],
        self::TASKS => [
            'label' => 'Background job runs',
            'description' => 'Each finished view sync and maintenance run, scheduled or started by someone. Their processing tasks, which status pages read, are kept either way.',
            'default' => true,
        ],
        self::SIGN_INS => [
            'label' => 'Sign-ins',
            'description' => 'Successful and failed sign-ins and sign-outs. A failed sign-in names the account only when it exists, so a mistyped password in the username field is never stored.',
            'default' => false,
        ],
    ];

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    /** @return array<string, bool> area => recorded, for every area */
    public static function defaults(): array
    {
        return array_map(static fn (array $area): bool => $area['default'], self::AREAS);
    }

    /**
     * Every area's switch as configured.
     *
     * @return array<string, bool>
     *
     * @throws \InvalidArgumentException when the YAML value is invalid
     */
    public function all(): array
    {
        $this->config->assertHealthy();

        return self::resolve($this->config->get(self::KEY));
    }

    /** Whether entries of this area are recorded; the default when the YAML is invalid. */
    public function isRecorded(string $area): bool
    {
        try {
            return $this->all()[$area] ?? false;
        } catch (\Throwable) {
            return self::defaults()[$area] ?? false;
        }
    }

    /**
     * Saves the given switches and keeps the others. Only switches that change
     * are written, so YAML keeps naming just the areas set by hand. Unknown
     * areas and values other than true and false are refused before anything
     * is written; an invalid stored value is replaced.
     *
     * @param array<string, mixed> $changes area => bool
     */
    public function save(array $changes): void
    {
        $validated = self::validate($changes);
        $this->config->updateMany(static function (array $current) use ($validated): array {
            try {
                $stored = self::validate($current[self::KEY] ?? []);
            } catch (\InvalidArgumentException) {
                $stored = [];
            }
            $effective = [...self::defaults(), ...$stored];
            foreach ($validated as $area => $recorded) {
                if ($effective[$area] !== $recorded) {
                    $stored[$area] = $recorded;
                }
            }

            return $stored === [] && !array_key_exists(self::KEY, $current) ? [] : [self::KEY => $stored];
        });
    }

    /**
     * @return array<string, bool>
     *
     * @throws \InvalidArgumentException
     */
    public static function resolve(mixed $value): array
    {
        if ($value === null) {
            return self::defaults();
        }

        return [...self::defaults(), ...self::validate($value)];
    }

    /**
     * @return array<string, bool>
     *
     * @throws \InvalidArgumentException
     */
    private static function validate(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException(self::KEY.' must be a mapping of areas to true or false.');
        }
        $validated = [];
        foreach ($value as $area => $recorded) {
            if (!is_string($area) || !isset(self::AREAS[$area])) {
                throw new \InvalidArgumentException(sprintf('%s has an unknown area. Use %s.', self::KEY, implode(', ', array_keys(self::AREAS))));
            }
            if (!is_bool($recorded)) {
                throw new \InvalidArgumentException(sprintf('%s.%s must be true or false.', self::KEY, $area));
            }
            $validated[$area] = $recorded;
        }

        return $validated;
    }
}
