<?php

declare(strict_types=1);

namespace App\Tests\Service\Cluster;

use App\Service\Cluster\BalancerConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The balancer's reference configuration: what the spec asks of it, checked
 * in the text, and, where Nginx or Caddy is installed, checked by them.
 */
final class BalancerConfigTest extends TestCase
{
    private const SERVERS = [
        ['name' => 'collect-1', 'address' => '10.0.0.11'],
        ['name' => 'collect-2', 'address' => 'fd00::12'],
    ];

    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            (new Filesystem())->remove($this->directory);
        }
    }

    public function testNginxSpreadsLimitsAndKeepsAddressesOutOfTheLogs(): void
    {
        $nginx = (new BalancerConfig('https://collect.example.com', self::SERVERS, 120))->nginx();

        self::assertStringContainsString("upstream aggregate_collection_collect_example_com {\n    least_conn;", $nginx);
        self::assertStringContainsString('server 10.0.0.11:80 max_fails=1 fail_timeout=10s; # collect-1', $nginx);
        self::assertStringContainsString('server [fd00::12]:80 max_fails=1 fail_timeout=10s; # collect-2', $nginx);
        self::assertStringContainsString("POST    \$binary_remote_addr;\n    default \"\";", $nginx, 'Only POST /api/receive is counted.');
        self::assertStringContainsString('zone=aggregate_receive_collect_example_com:10m rate=120r/m;', $nginx);
        self::assertStringContainsString('limit_req zone=aggregate_receive_collect_example_com burst=120 nodelay;', $nginx);
        self::assertStringContainsString('limit_req_status 429;', $nginx);
        self::assertSame(2, substr_count($nginx, 'access_log off;'), 'Neither server block writes an access log.');
        self::assertStringContainsString('error_log /var/log/nginx/aggregate-balancer-error.log error;', $nginx);
        self::assertStringContainsString('limit_req_log_level warn;', $nginx, 'Below the error log\'s level.');
        self::assertStringContainsString('proxy_set_header X-Forwarded-For $remote_addr;', $nginx);
        self::assertStringContainsString('proxy_set_header X-Forwarded-Port "";', $nginx);
        self::assertStringContainsString('proxy_next_upstream error timeout http_503 non_idempotent;', $nginx, 'A paused server\'s 503 goes to the next server.');
        self::assertStringContainsString('ssl_certificate /etc/letsencrypt/live/collect.example.com/fullchain.pem;', $nginx);
        self::assertStringContainsString('return 301 https://$host$request_uri;', $nginx);
        self::assertStringNotContainsString('add_header', $nginx, 'The collection servers set their own headers.');
    }

    public function testNginxSplitsIntoTheHalvesAHostingPanelTakes(): void
    {
        $balancer = new BalancerConfig('https://collect.example.com', self::SERVERS, 100);
        $http = $balancer->nginx(BalancerConfig::PART_HTTP);
        $server = $balancer->nginx(BalancerConfig::PART_SERVER);

        self::assertStringContainsString('upstream aggregate_collection_collect_example_com', $http);
        self::assertStringNotContainsString('location', $http);
        self::assertStringContainsString('location = /api/receive {', $server);
        self::assertStringNotContainsString('upstream aggregate', $server);
        self::assertStringNotContainsString('listen', $server, 'The panel owns listen, server_name and the certificate.');
        self::assertStringNotContainsString('ssl_certificate', $server);
    }

    public function testPlainHttpAndAPortAreKept(): void
    {
        $nginx = (new BalancerConfig('http://collect.example.com:8080/', self::SERVERS, 100, 8081))->nginx();

        self::assertStringContainsString('listen 8080;', $nginx);
        self::assertStringNotContainsString('ssl', $nginx);
        self::assertStringNotContainsString('return 301', $nginx);
        self::assertStringContainsString('server 10.0.0.11:8081 ', $nginx);
    }

    public function testCaddySpreadsChecksHealthAndOverwritesTheForwardedAddress(): void
    {
        $caddy = (new BalancerConfig('https://collect.example.com', self::SERVERS, 100))->caddy();

        self::assertStringContainsString("collect.example.com {\n", $caddy);
        self::assertStringContainsString("\treverse_proxy 10.0.0.11:80 [fd00::12]:80 {", $caddy);
        self::assertStringContainsString("\t\tlb_policy least_conn", $caddy);
        self::assertStringContainsString("\t\thealth_uri /api/health", $caddy);
        self::assertStringContainsString("\t\tunhealthy_status 503", $caddy);
        self::assertStringContainsString("\t\t\tHost collect.example.com", $caddy);
        self::assertStringContainsString("\t\theader_up X-Forwarded-For {remote_host}", $caddy);
        self::assertStringContainsString("\t\theader_up -X-Forwarded-Port", $caddy);
        self::assertStringNotContainsString("\tlog", $caddy, 'No access log.');
        self::assertStringNotContainsString('rate_limit {', $caddy, 'Stock Caddy has no rate limiter.');
        self::assertStringContainsString('the only one', $caddy);
    }

    public function testCaddyLimitsWithTheRateLimitModule(): void
    {
        $caddy = (new BalancerConfig('https://collect.example.com', self::SERVERS, 250))->caddy(true);

        self::assertStringContainsString("{\n\torder rate_limit before reverse_proxy\n}", $caddy);
        self::assertStringContainsString("\t\t\t\tmethod POST\n\t\t\t\tpath /api/receive", $caddy);
        self::assertStringContainsString("\t\t\tkey {remote_host}\n\t\t\tevents 250\n\t\t\twindow 1m", $caddy);
    }

    /** @param array<array{name: string, address: string}> $servers */
    #[DataProvider('refusals')]
    public function testWhatTheBalancerCannotServeIsRefusedWithTheFix(string $url, array $servers, int $rate, int $port, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new BalancerConfig($url, $servers, $rate, $port);
    }

    public static function refusals(): iterable
    {
        yield 'a path' => ['https://example.com/collect', self::SERVERS, 100, 80, 'cannot have a path'];
        yield 'not a URL' => ['collect.example.com', self::SERVERS, 100, 80, 'full http or https URL'];
        yield 'no servers' => ['https://collect.example.com', [], 100, 80, 'app:cluster:server add <name> <address> --role=collection'];
        yield 'a network' => ['https://collect.example.com', [['name' => 'collect-1', 'address' => '10.0.0.0/24']], 100, 80, 'collect-1\'s address in the cluster list is the network 10.0.0.0/24'];
        yield 'no limit' => ['https://collect.example.com', self::SERVERS, 0, 80, 'rate_limit_per_minute must be at least 1'];
        yield 'a bad port' => ['https://collect.example.com', self::SERVERS, 100, 70000, 'between 1 and 65535'];
    }

    public function testCertificatePathsCannotCarryDirectives(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BalancerConfig('https://collect.example.com', self::SERVERS, 100))->nginx(BalancerConfig::PART_SITE, '/etc/ssl/a.pem; include /etc/passwd');
    }

    public function testNginxAcceptsTheConfiguration(): void
    {
        $nginx = (new ExecutableFinder())->find('nginx', null, ['/usr/sbin', '/usr/local/sbin']);
        if ($nginx === null || !function_exists('openssl_pkey_new')) {
            self::markTestSkipped('Nginx or OpenSSL is not installed.');
        }
        $directory = $this->directory();
        [$certificate, $key] = $this->certificate($directory);
        $site = (new BalancerConfig('https://collect.example.com', self::SERVERS, 100))->nginx(BalancerConfig::PART_SITE, $certificate, $key);
        if (!is_file('/proc/net/if_inet6') || trim((string) file_get_contents('/proc/net/if_inet6')) === '') {
            // As the configuration says: a server without IPv6 drops those lines.
            $site = (string) preg_replace('/^\s*listen \[::\].*\n/m', '', $site);
        }
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            // nginx -t opens the listening sockets, which only root may do below 1024.
            $site = (string) preg_replace_callback('/^(\s*listen (?:\[::\]:)?)(80|443)\b/m', static fn (array $m): string => $m[1].($m[2] === '80' ? '8080' : '8443'), $site);
        }
        file_put_contents($directory.'/site.conf', str_replace('/var/log/nginx/', $directory.'/', $site));
        file_put_contents($directory.'/nginx.conf', "pid {$directory}/nginx.pid;\nerror_log {$directory}/error.log;\nevents {}\nhttp {\n    include {$directory}/site.conf;\n}\n");

        $process = new Process([$nginx, '-t', '-q', '-p', $directory, '-c', $directory.'/nginx.conf', '-e', $directory.'/error.log']);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
    }

    public function testCaddyAcceptsTheConfiguration(): void
    {
        $caddy = (new ExecutableFinder())->find('caddy');
        if ($caddy === null) {
            self::markTestSkipped('Caddy is not installed.');
        }
        $directory = $this->directory();
        file_put_contents($directory.'/Caddyfile', (new BalancerConfig('https://collect.example.com', self::SERVERS, 100))->caddy());

        $process = new Process([$caddy, 'validate', '--adapter', 'caddyfile', '--config', $directory.'/Caddyfile'], env: ['HOME' => $directory, 'XDG_DATA_HOME' => $directory, 'XDG_CONFIG_HOME' => $directory]);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
    }

    private function directory(): string
    {
        $this->directory = sys_get_temp_dir().'/aggregate-balancer-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);

        return $this->directory;
    }

    /** @return array{0: string, 1: string} */
    private function certificate(string $directory): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => 'collect.example.com'], $key);
        self::assertNotFalse($request);
        $certificate = openssl_csr_sign($request, null, $key, 1);
        self::assertNotFalse($certificate);
        openssl_x509_export_to_file($certificate, $directory.'/certificate.pem');
        openssl_pkey_export_to_file($key, $directory.'/key.pem');

        return [$directory.'/certificate.pem', $directory.'/key.pem'];
    }
}
