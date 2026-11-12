<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use App\Service\BootSettings;
use Symfony\Component\Yaml\Yaml;

/**
 * What this server does when several servers share one database: the
 * `server_role` setting in config/aggregate.yaml, or the SERVER_ROLE
 * environment variable, which takes precedence.
 *
 *  - all (the default): everything, as one server always has;
 *  - administration: the dashboard, setup, updates, the scheduled task, the
 *    reporting API and the MCP server; the only server that writes
 *    configuration or runs migrations;
 *  - collection: /api/receive and the browser scripts;
 *  - worker: Messenger consumers.
 *
 * The balancer in front of the collection servers runs no Aggregate, so it
 * has no role: app:server:balancer-config on the administration server
 * prints its configuration.
 *
 * Read when the kernel boots, the way the dashboard switch is, so the routes
 * and controllers a role does not use are never registered. A missing or
 * unknown value means `all`, and so does any role on SQLite, whose database
 * file cannot be shared: problem() then says why.
 */
final class ServerRole
{
    public const ALL = 'all';
    public const ADMINISTRATION = 'administration';
    public const COLLECTION = 'collection';
    public const WORKER = 'worker';
    public const ROLES = [self::ALL, self::ADMINISTRATION, self::COLLECTION, self::WORKER];

    public const CONFIG_KEY = 'server_role';
    public const ENVIRONMENT_VARIABLE = 'SERVER_ROLE';
    public const ADMINISTRATION_URL_KEY = 'administration_url';
    public const ADMINISTRATION_URL_VARIABLE = 'ADMINISTRATION_URL';

    /**
     * The route files a role loads, in config/. routes_dashboard.yaml is
     * loaded besides these wherever the dashboard is on, which only `all`
     * and `administration` allow.
     */
    public const ROUTE_FILES = [
        self::ALL => ['routes_health.yaml', 'routes_branding.yaml', 'routes_collection.yaml', 'routes_admin_api.yaml'],
        self::ADMINISTRATION => ['routes_health.yaml', 'routes_branding.yaml', 'routes_admin_api.yaml'],
        self::COLLECTION => ['routes_health.yaml', 'routes_branding.yaml', 'routes_collection.yaml'],
        self::WORKER => ['routes_health.yaml'],
    ];

    /**
     * Commands every role may run: help and diagnostics, the cache, assets
     * built on this server, and the read-only status commands. Each entry is
     * a command name, or a prefix ending in a colon.
     */
    private const COMMON_COMMANDS = [
        'list', 'help', 'completion', '_complete', 'about',
        'cache:', 'assets:install', 'asset-map:compile', 'importmap:', 'lint:', 'debug:', 'router:match',
        'doctrine:migrations:status', 'doctrine:migrations:list', 'doctrine:migrations:up-to-date',
        'app:server:role', 'app:cluster:sync', 'app:cluster:join', 'app:config',
        'app:updates:check', 'app:updates:verify-package', 'app:updates:maintenance',
        'app:email:status', 'app:alerts:status', 'app:scheduler:status', 'app:api:token:list',
        'app:audit:list', 'app:mcp:connection:list', 'app:tag-manager:sites',
    ];

    /**
     * Updates that only replace files, after the administration server has
     * run the migrations: the commands check --files-only themselves.
     */
    private const FILES_ONLY_UPDATE_COMMANDS = ['app:updates:apply', 'app:updates:deployed', 'app:updates:rollback'];

    /** Commands a role adds to the common ones. `all` and `administration` may run every command. */
    private const ROLE_COMMANDS = [
        self::COLLECTION => ['app:assets:build-js', ...self::FILES_ONLY_UPDATE_COMMANDS],
        self::WORKER => [
            'messenger:consume', 'messenger:stats', 'messenger:stop-workers',
            'messenger:failed:show', 'messenger:failed:retry', 'app:tracking:retry-failed', 'app:queue:retention',
            ...self::FILES_ONLY_UPDATE_COMMANDS,
        ],
    ];

    private function __construct(
        /** The role in effect. */
        public readonly string $role,
        /** The role as configured, lower-cased, or null when none is. */
        public readonly ?string $configured,
        /** Where the configured role came from: environment, configuration or default. */
        public readonly string $source,
        /** Why the configured role is not the one in effect, or null. */
        public readonly ?string $problem,
        /** The administration server's address, for messages and the configuration pull, or null. */
        public readonly ?string $administrationUrl,
    ) {
    }

    /** The role for an installation, from its environment and configuration. */
    public static function resolve(string $projectDir, string $environment): self
    {
        $configuration = BootSettings::read($projectDir, $environment);
        $fromEnvironment = self::environmentValue(self::ENVIRONMENT_VARIABLE);
        $fromConfiguration = $configuration[self::CONFIG_KEY] ?? null;
        $administrationUrl = self::environmentValue(self::ADMINISTRATION_URL_VARIABLE)
            ?? (is_string($configuration[self::ADMINISTRATION_URL_KEY] ?? null) ? trim($configuration[self::ADMINISTRATION_URL_KEY]) : null);

        [$value, $source] = match (true) {
            $fromEnvironment !== null => [$fromEnvironment, 'environment'],
            is_string($fromConfiguration) && trim($fromConfiguration) !== '' => [trim($fromConfiguration), 'configuration'],
            default => [null, 'default'],
        };

        return self::effective($value, $source, $administrationUrl !== '' ? $administrationUrl : null, self::databaseUrl());
    }

    /**
     * The same for a service that knows its project but not the kernel's
     * environment: APP_ENV, as bin/console and public/index.php set it.
     */
    public static function forProject(string $projectDir): self
    {
        return self::resolve($projectDir, self::environmentValue('APP_ENV') ?? 'prod');
    }

    /** A role as given, for tests and reports; the same rules as resolve(). */
    public static function of(string $role, ?string $databaseUrl = null, ?string $administrationUrl = null): self
    {
        return self::effective($role, 'configuration', $administrationUrl, $databaseUrl);
    }

    private static function effective(?string $value, string $source, ?string $administrationUrl, ?string $databaseUrl): self
    {
        if ($value === null) {
            return new self(self::ALL, null, 'default', null, $administrationUrl);
        }
        $configured = strtolower($value);
        if (!in_array($configured, self::ROLES, true)) {
            return new self(self::ALL, $configured, $source, sprintf(
                'The server role "%s" is not one of %s, so this server runs as all.%s',
                $value,
                implode(', ', self::ROLES),
                $configured === 'balancer'
                    ? ' A balancer runs no Aggregate: remove Aggregate from it and install the configuration app:server:balancer-config prints on the administration server.'
                    : '',
            ), $administrationUrl);
        }
        if ($configured !== self::ALL && self::isSqlite($databaseUrl)) {
            return new self(self::ALL, $configured, $source, sprintf(
                'SQLite keeps the database in a file on this server, which other servers cannot share, so the role %s does not apply and this server runs as all. Move to MySQL, MariaDB or PostgreSQL first.',
                $configured,
            ), $administrationUrl);
        }

        return new self($configured, $configured, $source, null, $administrationUrl);
    }

    public function isAll(): bool
    {
        return $this->role === self::ALL;
    }

    /** The dashboard, setup and every configuration writer. */
    public function administers(): bool
    {
        return $this->role === self::ALL || $this->role === self::ADMINISTRATION;
    }

    /** Whether the scheduled task (app:scheduler:run) runs here. */
    public function runsScheduledTask(): bool
    {
        return $this->administers();
    }

    /** Whether Messenger consumers run here. */
    public function runsConsumers(): bool
    {
        return $this->role === self::ALL || $this->role === self::WORKER;
    }

    /** Whether /api/receive and the browser scripts are served here. */
    public function collects(): bool
    {
        return $this->role === self::ALL || $this->role === self::COLLECTION;
    }

    /** @return list<string> file names in config/ */
    public function routeFiles(): array
    {
        return self::ROUTE_FILES[$this->role];
    }

    /**
     * The route files this role leaves out.
     *
     * @return list<string>
     */
    public function skippedRouteFiles(): array
    {
        return array_values(array_diff(self::ROUTE_FILES[self::ALL], self::ROUTE_FILES[$this->role]));
    }

    /**
     * The controller classes a route file loads, from the `resource:` of each
     * entry (../src/Controller/Name.php), so the controllers a role does not
     * route to are removed from the container without a list kept by hand.
     *
     * @return list<class-string|string>
     */
    public static function controllersInRouteFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $routes = Yaml::parseFile($path);
        if (!is_array($routes)) {
            return [];
        }
        $controllers = [];
        foreach ($routes as $route) {
            $resource = is_array($route) ? ($route['resource'] ?? null) : null;
            if (is_string($resource) && preg_match('~(?:^|/)src/Controller/((?:[A-Za-z0-9_]+/)*[A-Za-z0-9_]+)\.php$~', $resource, $match) === 1) {
                $controllers[] = 'App\\Controller\\'.str_replace('/', '\\', $match[1]);
            }
        }

        return array_values(array_unique($controllers));
    }

    public function allowsCommand(string $name): bool
    {
        if ($this->administers()) {
            return true;
        }
        foreach ([...self::COMMON_COMMANDS, ...self::ROLE_COMMANDS[$this->role]] as $allowed) {
            if ($name === $allowed || (str_ends_with($allowed, ':') && str_starts_with($name, $allowed))) {
                return true;
            }
        }

        return false;
    }

    /** "the administration server" with its address when known, for messages. */
    public function administrationServer(): string
    {
        return $this->administrationUrl !== null ? 'the administration server ('.$this->administrationUrl.')' : 'the administration server';
    }

    /** Why this server does not do something its role leaves to another server. */
    public function refusal(string $what): string
    {
        $elsewhere = str_starts_with($what, 'messenger:') ? 'a worker server' : $this->administrationServer();

        return sprintf('This is a %s server: %s runs on %s, not here.', $this->role, $what, $elsewhere);
    }

    /**
     * Why this server cannot update this way, or null: the server that
     * administers runs the migrations, so it never updates files only, and
     * every other server only ever does.
     */
    public function updateRefusal(bool $filesOnly): ?string
    {
        if ($this->administers() && $filesOnly) {
            return sprintf('This is the %s server, which runs the migrations, so it updates without --files-only.', $this->role === self::ALL ? 'only' : 'administration');
        }
        if (!$this->administers() && !$filesOnly) {
            return sprintf(
                'This is a %s server: %s runs the migrations. Update it first, then update this server with --files-only.',
                $this->role,
                $this->administrationServer(),
            );
        }

        return null;
    }

    public function assertAdministers(string $what): void
    {
        if (!$this->administers()) {
            throw new ServerRoleRefusal($this->refusal($what));
        }
    }

    /**
     * Refuses configuration changes on a server that does not administer:
     * its configuration comes from the administration server, so a change
     * made here would be lost or would split the cluster's settings.
     */
    public function assertWritesConfiguration(): void
    {
        if (!$this->administers()) {
            throw new ServerRoleRefusal(sprintf(
                'This is a %s server: its configuration is changed on %s and comes from there, so it is not changed here.',
                $this->role,
                $this->administrationServer(),
            ));
        }
    }

    private static function environmentValue(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private static function databaseUrl(): ?string
    {
        $value = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null;

        return is_string($value) ? $value : null;
    }

    private static function isSqlite(?string $databaseUrl): bool
    {
        return $databaseUrl !== null && preg_match('~^\s*(?:pdo-)?sqlite3?:~i', $databaseUrl) === 1;
    }
}
