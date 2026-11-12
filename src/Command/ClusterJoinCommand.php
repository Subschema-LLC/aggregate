<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Cluster\ClusterSettings;
use App\Service\Cluster\ServerRole;
use App\Service\CollectionAddress;
use App\Service\LocalEnvironmentFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Makes this installation a server of a cluster: its role, name, the
 * administration server's address, the shared key and, optionally, the
 * shared database and the balancer's address, all in .env.local, the file
 * this server alone owns. Secrets come from standard input as JSON, never
 * from the command line, so a hosting panel can pass them as they are:
 *
 *     {"role": "collection", "name": "collect-1", "administration_url": "https://admin.example.com",
 *      "cluster_key": "...", "database_url": "mysql://...", "trusted_proxies": "10.0.0.2", "app_secret": "..."}
 *
 * app_secret is the administration server's APP_SECRET: organization-traffic
 * codes are made on one collection server and checked on whichever the
 * balancer picks next, so every collection server needs the same one.
 */
#[AsCommand(
    name: 'app:cluster:join',
    description: 'Make this server part of a cluster: role, name, administration server, shared key and database, from JSON on standard input',
)]
final class ClusterJoinCommand extends Command
{
    private const FIELDS = ['role', 'name', 'administration_url', 'cluster_key', 'database_url', 'trusted_proxies', 'app_secret'];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%kernel.cache_dir%')]
        private readonly ?string $cacheDir = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stdin', null, InputOption::VALUE_NONE, 'Read the settings as one JSON object from standard input (required)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('stdin')) {
            $output->writeln('<error>Pass the settings as JSON on standard input, with --stdin: '.implode(', ', self::FIELDS).'.</error>');

            return Command::INVALID;
        }
        $raw = stream_get_contents(\STDIN, 65536);
        try {
            $settings = json_decode((string) $raw, true, 8, \JSON_THROW_ON_ERROR);
            $variables = self::variables(is_array($settings) ? $settings : []);
        } catch (\JsonException|\InvalidArgumentException $e) {
            $output->writeln('<error>'.($e instanceof \JsonException ? 'Standard input is not one JSON object.' : $e->getMessage()).'</error>');

            return Command::INVALID;
        }

        $file = new LocalEnvironmentFile($this->projectDir, $this->environment);
        foreach (array_keys($variables) as $name) {
            if (($problem = $file->problem($name)) !== null) {
                $output->writeln('<error>'.$problem.'</error>');

                return Command::FAILURE;
            }
        }
        foreach ($variables as $name => $value) {
            $file->set($name, $value, 'Cluster (app:cluster:join)');
        }

        if ($this->cacheDir !== null) {
            self::discardCacheAtExit($this->cacheDir);
        }

        $output->writeln(sprintf('This server joins the cluster as %s "%s". Saved %s in .env.local.', $variables['SERVER_ROLE'], $variables[ClusterSettings::SERVER_NAME_VARIABLE], implode(', ', array_keys($variables))));
        $output->writeln('The cache, built for the previous role, is removed as this command ends, so the next command or request builds it for this one.');
        $output->writeln('Next:');
        $output->writeln('  1. Reload PHP-FPM.');
        if ($variables['SERVER_ROLE'] !== ServerRole::ADMINISTRATION) {
            $output->writeln(sprintf('  2. On the administration server: php bin/console app:cluster:server add %s <this server\'s address> --role=%s', $variables[ClusterSettings::SERVER_NAME_VARIABLE], $variables['SERVER_ROLE']));
        }
        $output->writeln('  '.($variables['SERVER_ROLE'] !== ServerRole::ADMINISTRATION ? '3' : '2').'. Run php bin/console app:cluster:sync every minute, as the web server\'s user, and once now.');

        return Command::SUCCESS;
    }

    /**
     * Removes the cache directory once this process has finished with it.
     * The role is compiled into the container, and cache:clear run in the
     * same second as the build that it would replace keeps the old one
     * ("Cache is fresh"), so a scripted join followed by cache:clear could
     * leave the previous role in force.
     */
    public static function discardCacheAtExit(string $cacheDir): void
    {
        register_shutdown_function(static function () use ($cacheDir): void {
            if (!is_dir($cacheDir)) {
                return;
            }
            $stale = $cacheDir.'~'.bin2hex(random_bytes(4));
            if (@rename($cacheDir, $stale)) {
                (new Filesystem())->remove($stale);
            }
        });
    }

    /**
     * The .env.local variables for the settings given, validated.
     *
     * @param array<string, mixed> $settings
     * @return array<string, string>
     */
    public static function variables(array $settings): array
    {
        $unknown = array_diff(array_keys($settings), self::FIELDS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown setting(s): '.implode(', ', $unknown).'. Use '.implode(', ', self::FIELDS).'.');
        }
        $role = $settings['role'] ?? null;
        if (!is_string($role) || !in_array($role, [ServerRole::ADMINISTRATION, ServerRole::COLLECTION, ServerRole::WORKER], true)) {
            throw new \InvalidArgumentException('role must be administration, collection or worker. The balancer runs no Aggregate: app:server:balancer-config on the administration server prints its configuration.');
        }
        $name = ClusterSettings::server($settings['name'] ?? null, '127.0.0.1')['name'];
        $variables = ['SERVER_ROLE' => $role, ClusterSettings::SERVER_NAME_VARIABLE => $name];

        $key = $settings['cluster_key'] ?? null;
        if (!is_string($key) || strlen($key) < ClusterSettings::MIN_KEY_LENGTH || preg_match('/^[A-Za-z0-9_\-+\/=]+$/D', $key) !== 1) {
            throw new \InvalidArgumentException('cluster_key must be the administration server\'s CLUSTER_KEY (app:cluster:key shows it).');
        }
        $variables[ClusterSettings::KEY_VARIABLE] = $key;

        if ($role !== ServerRole::ADMINISTRATION) {
            $administration = CollectionAddress::normalize($settings['administration_url'] ?? '');
            if ($administration === '') {
                throw new \InvalidArgumentException('administration_url must be the administration server\'s address, such as https://admin.example.com.');
            }
            $variables[ServerRole::ADMINISTRATION_URL_VARIABLE] = $administration;
        }

        if (isset($settings['database_url'])) {
            $database = $settings['database_url'];
            if (!is_string($database) || preg_match('~^(?:mysql|mariadb|pdo-mysql|postgres(?:ql)?|pgsql|pdo-pgsql)://~i', $database) !== 1) {
                throw new \InvalidArgumentException('database_url must be the shared MySQL, MariaDB or PostgreSQL database\'s URL; SQLite cannot be shared.');
            }
            $variables['DATABASE_URL'] = $database;
        }
        if (isset($settings['app_secret'])) {
            $secret = $settings['app_secret'];
            if (!is_string($secret) || strlen($secret) < 32 || preg_match('/^[A-Za-z0-9_\-]+$/D', $secret) !== 1) {
                throw new \InvalidArgumentException('app_secret must be the administration server\'s APP_SECRET: at least 32 letters, digits, - or _.');
            }
            $variables['APP_SECRET'] = $secret;
        }
        if (isset($settings['trusted_proxies'])) {
            $proxies = $settings['trusted_proxies'];
            foreach (is_string($proxies) ? explode(',', $proxies) : [null] as $proxy) {
                if (!is_string($proxy) || !ClusterSettings::isAddress(trim($proxy))) {
                    throw new \InvalidArgumentException('trusted_proxies must be the balancer\'s address or network, or several separated by commas.');
                }
            }
            $variables['TRUSTED_PROXIES'] = implode(',', array_map('trim', explode(',', (string) $proxies)));
        }

        return $variables;
    }
}
