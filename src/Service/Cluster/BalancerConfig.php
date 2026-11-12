<?php

declare(strict_types=1);

namespace App\Service\Cluster;

/**
 * The reference configuration of the balancer in front of a cluster's
 * collection servers, for Nginx or Caddy, built from the collection address
 * and the collection servers in the `cluster` list. The balancer runs no
 * Aggregate: an operator, or PRAGMA, installs what this builds.
 *
 * Both formats:
 *  - spread requests over the collection servers with least connections,
 *    over the private network, keeping the visitor's Host header;
 *  - write no access log;
 *  - overwrite X-Forwarded-For with the connecting address and drop
 *    X-Forwarded-Port, so a visitor cannot choose the address the servers use
 *    for rate limits and geography;
 *  - take a paused server out of rotation.
 *
 * Nginx also limits POST /api/receive per address at rate_limit_per_minute,
 * answering 429 and logging the rejection below the error log's level, and
 * passes a paused server's 503 on to the next server, which is safe because
 * a paused server recorded nothing. Stock Caddy has no rate limiter, so its
 * configuration limits only with the caddy-ratelimit module, and finds a
 * paused server through /api/health.
 */
final class BalancerConfig
{
    public const NGINX = 'nginx';
    public const CADDY = 'caddy';
    public const PART_SITE = 'site';
    public const PART_HTTP = 'http';
    public const PART_SERVER = 'server';
    public const PARTS = [self::PART_SITE, self::PART_HTTP, self::PART_SERVER];
    /** The same request body limit as the single-server reference (docs/nginx/aggregate-analytics.conf). */
    private const MAX_BODY_KILOBYTES = 64;
    private const PATH_PATTERN = '~^/[A-Za-z0-9._/@+-]+$~D';

    private readonly string $scheme;
    private readonly string $host;
    private readonly ?int $port;
    /** @var list<array{name: string, address: string, port: int}> */
    private readonly array $servers;
    /** Lower-case letters, digits and underscores from the host, so two installations can share one Nginx. */
    private readonly string $suffix;

    /**
     * @param string $collectionUrl the collection address (collection_base_url)
     * @param array<array{name: string, address: string, port?: ?int}> $servers the collection servers, each at its port or $upstreamPort
     * @param int $ratePerMinute rate_limit_per_minute
     * @param int $upstreamPort cluster_collection_port: the port the collection servers listen on over the private network
     */
    public function __construct(
        string $collectionUrl,
        array $servers,
        private readonly int $ratePerMinute,
        private readonly int $upstreamPort = 80,
    ) {
        $parts = parse_url($collectionUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('The collection address (collection_base_url) must be a full http or https URL, such as https://collect.example.com.');
        }
        if (($parts['path'] ?? '') !== '' && $parts['path'] !== '/') {
            throw new \InvalidArgumentException('The balancer serves the collection address at its root, so collection_base_url cannot have a path: use a hostname of its own, such as https://collect.example.com.');
        }
        $host = strtolower($parts['host']);
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1 && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new \InvalidArgumentException('The collection address\'s host name is not one a balancer can serve.');
        }
        if ($servers === []) {
            throw new \InvalidArgumentException('No collection server is in the cluster list. Add each one with: php bin/console app:cluster:server add <name> <address> --role=collection');
        }
        $list = [];
        foreach ($servers as $server) {
            if (filter_var($server['address'], FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException(sprintf(
                    '%s\'s address in the cluster list is the network %s. The balancer needs the one address it reaches the server at: php bin/console app:cluster:server add %s <address> --role=collection',
                    $server['name'],
                    $server['address'],
                    $server['name'],
                ));
            }
            $port = $server['port'] ?? $upstreamPort;
            if ($port < 1 || $port > 65535) {
                throw new \InvalidArgumentException('The collection servers\' port must be between 1 and 65535.');
            }
            $list[] = ['name' => $server['name'], 'address' => $server['address'], 'port' => $port];
        }
        if ($ratePerMinute < 1) {
            throw new \InvalidArgumentException('rate_limit_per_minute must be at least 1 for the balancer\'s limit.');
        }
        if ($upstreamPort < 1 || $upstreamPort > 65535) {
            throw new \InvalidArgumentException('The collection servers\' port must be between 1 and 65535.');
        }

        $this->scheme = $parts['scheme'];
        $this->host = $host;
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->servers = $list;
        $this->suffix = trim((string) preg_replace('/[^a-z0-9]+/', '_', $host), '_');
    }

    public function host(): string
    {
        return $this->host;
    }

    /** @return list<array{name: string, address: string, port: int}> */
    public function servers(): array
    {
        return $this->servers;
    }

    /**
     * The Nginx configuration: a whole site file (`site`, included from the
     * http context), or the two halves a hosting panel needs, the blocks for
     * the http context (`http`) and the directives inside the domain's server
     * block (`server`).
     */
    public function nginx(string $part = self::PART_SITE, ?string $certificate = null, ?string $certificateKey = null): string
    {
        if (!in_array($part, self::PARTS, true)) {
            throw new \InvalidArgumentException('The Nginx part is site, http or server.');
        }
        $certificate ??= '/etc/letsencrypt/live/'.$this->host.'/fullchain.pem';
        $certificateKey ??= '/etc/letsencrypt/live/'.$this->host.'/privkey.pem';
        foreach ([$certificate, $certificateKey] as $path) {
            if (preg_match(self::PATH_PATTERN, $path) !== 1) {
                throw new \InvalidArgumentException('A certificate path must be an absolute path of letters, digits, dots, hyphens, underscores and slashes.');
            }
        }

        $out = $this->header('--nginx'.($part !== self::PART_SITE ? ' --part='.$part : ''));
        if ($part === self::PART_SITE) {
            $out[] = '# Include this file from Nginx\'s http context, for example as';
            $out[] = '# /etc/nginx/sites-available/aggregate-balancer.conf linked from sites-enabled/.';
            $out[] = '# On a server without IPv6, remove the "listen [::]" lines.';
            $out[] = '';
        } elseif ($part === self::PART_HTTP) {
            $out[] = '# For Nginx\'s http context, for example /etc/nginx/conf.d/aggregate-balancer.conf.';
            $out[] = '# The directives for the domain\'s server block come from --part=server.';
            $out[] = '';
        } else {
            $out[] = '# For the domain\'s server block, such as a hosting panel\'s additional Nginx';
            $out[] = '# directives. The http context needs --part=http as well.';
            $out[] = '';
        }

        if ($part !== self::PART_SERVER) {
            array_push($out, ...$this->nginxHttp());
        }
        if ($part === self::PART_SERVER) {
            array_push($out, ...$this->nginxServer(''));
        } elseif ($part === self::PART_SITE) {
            array_push($out, ...$this->nginxSite($certificate, $certificateKey));
        }

        return implode("\n", $out)."\n";
    }

    /**
     * The Caddyfile. With $rateLimitModule, it limits POST /api/receive per
     * address with the caddy-ratelimit module, which stock Caddy lacks.
     */
    public function caddy(bool $rateLimitModule = false): string
    {
        $out = $this->header('--caddy'.($rateLimitModule ? ' --caddy-rate-limit' : ''));
        if ($rateLimitModule) {
            $out[] = '# Needs a Caddy built with github.com/mholt/caddy-ratelimit, for example:';
            $out[] = '#   xcaddy build --with github.com/mholt/caddy-ratelimit';
            $out[] = '{';
            $out[] = "\torder rate_limit before reverse_proxy";
            $out[] = '}';
        } else {
            $out[] = '# Stock Caddy has no rate limiter, so each collection server\'s own limit';
            $out[] = '# (rate_limit_per_minute, counted on each server) is the only one. With a';
            $out[] = '# Caddy built with github.com/mholt/caddy-ratelimit, run the command with';
            $out[] = '# --caddy-rate-limit to limit per address here.';
        }
        $out[] = '';

        $siteAddress = $this->scheme === 'http' ? 'http://'.$this->hostWithPort() : $this->hostWithPort();
        $out[] = $siteAddress.' {';
        $out[] = "\t# Caddy writes no access log unless a log directive asks for one, so";
        $out[] = "\t# there is none here.";
        $out[] = "\trequest_body {";
        $out[] = "\t\tmax_size ".self::MAX_BODY_KILOBYTES.'KB';
        $out[] = "\t}";
        if ($rateLimitModule) {
            $out[] = '';
            $out[] = "\t# POST /api/receive per address, answering 429.";
            $out[] = "\trate_limit {";
            $out[] = "\t\tzone aggregate_receive_".$this->suffix.' {';
            $out[] = "\t\t\tmatch {";
            $out[] = "\t\t\t\tmethod POST";
            $out[] = "\t\t\t\tpath /api/receive";
            $out[] = "\t\t\t}";
            $out[] = "\t\t\tkey {remote_host}";
            $out[] = "\t\t\tevents ".$this->ratePerMinute;
            $out[] = "\t\t\twindow 1m";
            $out[] = "\t\t}";
            $out[] = "\t}";
        }
        $out[] = '';
        $upstreams = array_map(fn (array $server): string => $this->upstreamAddress($server['address'], $server['port']), $this->servers);
        $out[] = "\t# ".implode(', ', array_column($this->servers, 'name'));
        $out[] = "\treverse_proxy ".implode(' ', $upstreams).' {';
        $out[] = "\t\tlb_policy least_conn";
        $out[] = "\t\tlb_try_duration 5s";
        $out[] = '';
        $out[] = "\t\t# A paused server answers 503 on /api/health and leaves the rotation";
        $out[] = "\t\t# until it answers 200 again. Its first 503 on /api/receive reaches";
        $out[] = "\t\t# the visitor, since Caddy does not retry a POST that was sent.";
        $out[] = "\t\thealth_uri /api/health";
        $out[] = "\t\thealth_interval 5s";
        $out[] = "\t\thealth_timeout 3s";
        $out[] = "\t\thealth_status 200";
        $out[] = "\t\thealth_headers {";
        $out[] = "\t\t\tHost ".$this->hostWithPort();
        $out[] = "\t\t}";
        $out[] = "\t\tfail_duration 30s";
        $out[] = "\t\tunhealthy_status 503";
        $out[] = '';
        $out[] = "\t\t# Overwritten, never appended: the collection servers trust these";
        $out[] = "\t\t# from the balancer only (TRUSTED_PROXIES).";
        $out[] = "\t\theader_up X-Forwarded-For {remote_host}";
        $out[] = "\t\theader_up X-Forwarded-Proto {scheme}";
        $out[] = "\t\theader_up -X-Forwarded-Port";
        $out[] = "\t}";
        $out[] = '}';

        return implode("\n", $out)."\n";
    }

    /** @return list<string> */
    private function header(string $options): array
    {
        return [
            '# Aggregate balancer for '.$this->hostWithPort().', from',
            '#   php bin/console app:server:balancer-config '.$options,
            '# on the administration server, from the '.count($this->servers).' collection server'.(count($this->servers) === 1 ? '' : 's')
                .' in its cluster list',
            '# and rate_limit_per_minute ('.$this->ratePerMinute.').',
            '# Run it again after adding or removing a collection server or changing',
            '# rate_limit_per_minute. Each collection server needs TRUSTED_PROXIES set to',
            '# this balancer\'s private address, and should accept web traffic from it only.',
            '',
        ];
    }

    /** @return list<string> */
    private function nginxHttp(): array
    {
        $out = [
            '# POST /api/receive is limited per address. Other requests, including CORS',
            '# preflights, have an empty key, which Nginx does not count.',
            'map $request_method $aggregate_receive_key_'.$this->suffix.' {',
            '    POST    $binary_remote_addr;',
            '    default "";',
            '}',
            'limit_req_zone $aggregate_receive_key_'.$this->suffix.' zone=aggregate_receive_'.$this->suffix.':10m rate='.$this->ratePerMinute.'r/m;',
            '',
            'upstream aggregate_collection_'.$this->suffix.' {',
            '    least_conn;',
            '    # A server that fails, or answers 503 because it paused, rests for 10 seconds.',
        ];
        foreach ($this->servers as $server) {
            $out[] = '    server '.$this->upstreamAddress($server['address'], $server['port']).' max_fails=1 fail_timeout=10s; # '.$server['name'];
        }
        $out[] = '    keepalive 16;';
        $out[] = '}';
        $out[] = '';

        return $out;
    }

    /** @return list<string> */
    private function nginxSite(string $certificate, string $certificateKey): array
    {
        $out = [];
        $listen = $this->port ?? ($this->scheme === 'https' ? 443 : 80);
        if ($this->scheme === 'https' && $this->port === null) {
            array_push($out,
                'server {',
                '    listen 80;',
                '    listen [::]:80;',
                '    server_name '.$this->host.';',
                '    access_log off;',
                '',
                '    # Certificate renewals by web root (certbot certonly --webroot -w /var/www/html).',
                '    location ^~ /.well-known/acme-challenge/ {',
                '        root /var/www/html;',
                '    }',
                '    location / {',
                '        return 301 https://$host$request_uri;',
                '    }',
                '}',
                '',
            );
        }
        $out[] = 'server {';
        if ($this->scheme === 'https') {
            $out[] = '    listen '.$listen.' ssl;';
            $out[] = '    listen [::]:'.$listen.' ssl;';
            $out[] = '    # On Nginx 1.25.1 or later, add: http2 on;';
            $out[] = '    server_name '.$this->host.';';
            $out[] = '    ssl_certificate '.$certificate.';';
            $out[] = '    ssl_certificate_key '.$certificateKey.';';
        } else {
            $out[] = '    listen '.$listen.';';
            $out[] = '    listen [::]:'.$listen.';';
            $out[] = '    server_name '.$this->host.';';
        }
        $out[] = '';
        array_push($out, ...$this->nginxServer('    '));
        $out[] = '}';

        return $out;
    }

    /** @return list<string> */
    private function nginxServer(string $indent): array
    {
        $upstream = 'http://aggregate_collection_'.$this->suffix;
        $lines = [
            '# No access log, so visitors\' addresses are not written here either.',
            'access_log off;',
            '# Rate-limit rejections are logged at warn, below this log\'s level, so',
            '# they are not written. Failures to reach the collection servers are,',
            '# with the address of the request that met them.',
            'error_log /var/log/nginx/aggregate-balancer-error.log error;',
            'limit_req_log_level warn;',
            'limit_req_status 429;',
            'client_max_body_size '.self::MAX_BODY_KILOBYTES.'k;',
            '',
            'proxy_http_version 1.1;',
            'proxy_set_header Connection "";',
            'proxy_set_header Host $host;',
            '# Overwritten, never appended: the collection servers trust these from',
            '# the balancer only (TRUSTED_PROXIES).',
            'proxy_set_header X-Forwarded-For $remote_addr;',
            'proxy_set_header X-Forwarded-Proto $scheme;',
            'proxy_set_header X-Forwarded-Port "";',
            'proxy_connect_timeout 2s;',
            '',
            'location = /api/receive {',
            '    limit_req zone=aggregate_receive_'.$this->suffix.' burst='.$this->ratePerMinute.' nodelay;',
            '    # A paused server answers 503 and records nothing, so the event goes to',
            '    # the next server. non_idempotent allows that for a POST; it also',
            '    # retries an event whose server failed after receiving it, which can',
            '    # count that one event twice.',
            '    proxy_next_upstream error timeout http_503 non_idempotent;',
            '    proxy_read_timeout 30s;',
            '    proxy_pass '.$upstream.';',
            '}',
            '',
            'location / {',
            '    proxy_next_upstream error timeout http_502 http_503 http_504;',
            '    proxy_pass '.$upstream.';',
            '}',
        ];

        return array_map(static fn (string $line): string => $line === '' ? '' : $indent.$line, $lines);
    }

    private function upstreamAddress(string $ip, int $port): string
    {
        return (str_contains($ip, ':') ? '['.$ip.']' : $ip).':'.$port;
    }

    private function hostWithPort(): string
    {
        return $this->host.($this->port !== null ? ':'.$this->port : '');
    }
}
