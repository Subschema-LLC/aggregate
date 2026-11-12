<?php

declare(strict_types=1);

namespace App\Service\Cluster;

use App\Service\InstalledRelease;
use App\Service\Operations\TaskTrigger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What app:cluster:sync does each minute, by the server's role:
 *
 *  - administration: records the configuration's version when it changed,
 *    and alerts when a server has not pulled for the pause limit;
 *  - collection and worker: pull the configuration from the administration
 *    server and record what they know in var/cluster_state.json;
 *  - all and balancer: nothing.
 *
 * When a pull fails, the version recorded in the database decides: the
 * server's own version means nothing changed, so it is verified and keeps
 * collecting; a newer one means it is behind, from the first such minute.
 * With no version recorded, or no database, nothing is verified.
 */
class ClusterSync
{
    public const PATH = '/api/cluster/config';
    public const RELEASE_HEADER = 'X-Aggregate-Cluster-Release';
    public const ROLE_HEADER = 'X-Aggregate-Cluster-Role';
    public const TIMEOUT_SECONDS = 20.0;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly ServerRole $role,
        private readonly ClusterSettings $settings,
        private readonly ClusterState $state,
        private readonly ClusterServers $servers,
        private readonly ClusterConfigPublisher $publisher,
        private readonly ClusterAlerts $alerts,
        private readonly InstalledRelease $release,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? Clock::get();
    }

    /** @return array{ok: bool, messages: list<string>} */
    public function run(): array
    {
        return match ($this->role->role) {
            ServerRole::ADMINISTRATION => $this->publishAndWatch(),
            ServerRole::COLLECTION, ServerRole::WORKER => $this->pull(),
            default => ['ok' => true, 'messages' => ['This server\'s role is '.$this->role->role.', which takes no part in the configuration pull.']],
        };
    }

    /** @return array{ok: bool, messages: list<string>} */
    private function publishAndWatch(): array
    {
        $now = $this->clock->now()->getTimestamp();
        $published = $this->publisher->publish(TaskTrigger::schedule());
        $messages = [$published['recorded']
            ? 'Recorded the new configuration version '.substr($published['version'], 0, 12).'.'
            : 'The configuration is unchanged ('.substr($published['version'], 0, 12).').'];

        $limit = $this->settings->maxAgeMinutes() * 60;
        foreach ($this->servers->expect(array_keys($this->settings->servers()), $now) as $name => $server) {
            $since = $server['last_pull_at'] ?? $server['first_seen_at'];
            $late = $now - $since > $limit;
            if ($late && $server['alert_since'] === null) {
                $condition = $server['last_pull_at'] === null
                    ? sprintf('%s has not pulled its configuration since it was added to the cluster, %d minutes ago.', $name, intdiv($now - $since, 60))
                    : sprintf('%s has not pulled its configuration for %d minutes, so it has paused collection or will.', $name, intdiv($now - $since, 60));
                $messages[] = $condition;
                array_push($messages, ...$this->alerts->send($name, 'firing', $since, $now, $condition));
                $this->servers->setAlert($name, $since);
            } elseif (!$late && $server['alert_since'] !== null) {
                $condition = sprintf('%s pulls its configuration again.', $name);
                $messages[] = $condition;
                array_push($messages, ...$this->alerts->send($name, 'recovered', $server['alert_since'], $now, $condition));
                $this->servers->setAlert($name, null);
            } elseif ($late) {
                $messages[] = sprintf('%s is still not pulling its configuration (alerted).', $name);
            }
        }

        return ['ok' => true, 'messages' => $messages];
    }

    /** @return array{ok: bool, messages: list<string>} */
    private function pull(): array
    {
        $now = $this->clock->now()->getTimestamp();
        $state = $this->state->read();
        try {
            [$status, $body] = $this->request($state['version']);
            if ($status === 304) {
                $state = ['verified_at' => $now, 'behind_since' => null, 'problem' => null] + $state;
                $this->state->write($state);

                return ['ok' => true, 'messages' => ['The configuration is the newest ('.substr((string) $state['version'], 0, 12).').']];
            }
            if ($status !== 200) {
                throw new \RuntimeException($status === 404
                    ? 'The administration server refused the request (404): check CLUSTER_KEY, this server\'s name and address in its cluster list, and both clocks.'
                    : 'The administration server answered '.$status.'.');
            }
            $bundle = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);
            $files = ConfigBundle::decode($bundle);
            $applied = ConfigBundle::apply($this->projectDir, $this->environment, $files);
            $this->state->write(['version' => $bundle['version'], 'verified_at' => $now, 'behind_since' => null, 'pulled_at' => $now, 'problem' => null]);

            return ['ok' => true, 'messages' => [sprintf(
                'Pulled configuration %s: %d file(s) changed, %d removed.',
                substr($bundle['version'], 0, 12),
                count($applied['written']),
                count($applied['removed']),
            )]];
        } catch (\Throwable $e) {
            return $this->afterFailedPull($state, $now, $e->getMessage());
        }
    }

    /**
     * @param array{version: ?string, verified_at: ?int, behind_since: ?int, pulled_at: ?int, problem: ?string} $state
     * @return array{ok: bool, messages: list<string>}
     */
    private function afterFailedPull(array $state, int $now, string $problem): array
    {
        try {
            $recorded = $this->publisher->recordedVersion();
        } catch (\Throwable) {
            $state['problem'] = $problem.' The database could not be reached either, so nothing was verified.';
            $this->state->write($state);

            return ['ok' => false, 'messages' => [$state['problem']]];
        }
        if ($recorded === null) {
            $state['problem'] = $problem.' The administration server has not recorded a configuration version yet, so nothing was verified.';
        } elseif ($state['version'] !== null && hash_equals($recorded, $state['version'])) {
            $state['verified_at'] = $now;
            $state['behind_since'] = null;
            $state['problem'] = $problem.' The recorded version is this server\'s own, so its configuration is still the newest.';
        } else {
            $state['behind_since'] ??= $now;
            $state['problem'] = $problem.' A newer configuration version is recorded, so this server is behind since '.gmdate('H:i', $state['behind_since']).' UTC.';
        }
        $this->state->write($state);

        return ['ok' => false, 'messages' => [$state['problem']]];
    }

    /** @return array{0: int, 1: string} status and body */
    private function request(?string $version): array
    {
        $administration = $this->role->administrationUrl;
        $key = $this->settings->key();
        if ($administration === null || preg_match('~^https?://~i', $administration) !== 1) {
            throw new \RuntimeException('ADMINISTRATION_URL is not set to the administration server\'s address.');
        }
        if ($key === null) {
            throw new \RuntimeException('CLUSTER_KEY is not set, or is shorter than '.ClusterSettings::MIN_KEY_LENGTH.' characters.');
        }
        $url = rtrim($administration, '/').self::PATH;
        $path = (string) parse_url($url, \PHP_URL_PATH);
        $headers = ClusterSignature::headers($key, 'GET', $path, $this->settings->serverName(), $this->clock->now()->getTimestamp());
        $headers[self::ROLE_HEADER] = $this->role->role;
        try {
            $release = $this->release->read()['version'] ?? null;
            if (is_string($release)) {
                $headers[self::RELEASE_HEADER] = $release;
            }
        } catch (\RuntimeException) {
            // A server without release.json simply reports no release.
        }
        $response = $this->httpClient->request('GET', $url, [
            'headers' => $headers,
            'query' => $version !== null ? ['have' => $version] : [],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_redirects' => 0,
        ]);
        $status = $response->getStatusCode();

        return [$status, $status === 200 ? $response->getContent(false) : ''];
    }
}
