<?php

declare(strict_types=1);

namespace App\Tests\Service\Alerts;

use App\Service\Alerts\AlertRuleValidator;
use App\Service\Alerts\AlertSecrets;
use App\Service\Alerts\WebhookSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebhookSenderTest extends TestCase
{
    private string $projectDir;
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/aggregate-webhook-sender-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/config', 0700, true);
        (new AlertSecrets($this->projectDir))->set('signing-secret-xyz');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testSendsSignedSingleElementJsonArrayWithExactTenFieldContract(): void
    {
        $captured = [];
        $clock = new MockClock('2025-06-02 14:05:00 UTC');
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"ok":true}', ['http_code' => 202]);
        });

        $sender = $this->sender($client, $clock);
        $payload = $this->samplePayload();
        $result = $sender->send([
            'id' => 'geektown-hub',
            'label' => 'Geektown Hub',
            'url' => 'https://hooks.geektown.example/alerts/ingest?key=super-secret-path-token',
        ], $payload);

        self::assertTrue($result['sent']);
        self::assertSame(202, $result['status_code']);
        self::assertSame(1, $result['attempts']);
        self::assertSame('HTTP 202 (id a1b2c3d4e5f60718293a4b5c)', $result['reason']);

        self::assertCount(1, $captured);
        $body = (string) $captured[0]['options']['body'];
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded, 'Body is a single-element JSON array.');
        self::assertSame([
            'schema_version',
            'id',
            'incident',
            'type',
            'rule',
            'website',
            'host',
            'installation',
            'alert_condition',
            'window_end',
        ], array_keys($decoded[0]));

        $expectedTimestamp = $clock->now()->getTimestamp();
        $expectedHmac = hash_hmac('sha256', $expectedTimestamp.'.'.$body, 'signing-secret-xyz');
        $headers = $captured[0]['options']['normalized_headers'];
        self::assertSame(['X-Aggregate-Signature: t='.$expectedTimestamp.',v1='.$expectedHmac], $headers['x-aggregate-signature']);
        self::assertSame(['Idempotency-Key: '.$payload['id']], $headers['idempotency-key']);
    }

    public function testRetriesThreeTimesSigningAFreshTimestampWhileKeepingIdempotencyKey(): void
    {
        $signatures = [];
        $idempotencyKeys = [];
        $clock = new MockClock('2025-06-02 14:05:00 UTC');

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$signatures, &$idempotencyKeys, $clock): MockResponse {
            $headers = $options['normalized_headers'];
            $signatures[] = $headers['x-aggregate-signature'][0];
            $idempotencyKeys[] = $headers['idempotency-key'][0];
            $clock->modify('+2 seconds');

            return new MockResponse('Service Unavailable', ['http_code' => 503]);
        });

        $result = $this->sender($client, $clock)->send([
            'id' => 'geektown-hub',
            'label' => 'Geektown Hub',
            'url' => 'https://hooks.geektown.example/alerts/ingest?secret=token-999',
        ], $this->samplePayload());

        self::assertFalse($result['sent']);
        self::assertSame(3, $result['attempts']);
        self::assertSame('No answer after 3 tries: the receiver answered HTTP 503.', $result['reason']);
        self::assertCount(3, array_unique($signatures), 'Each retry signs a fresh timestamp.');
        self::assertCount(1, array_unique($idempotencyKeys), 'All retries share the same Idempotency-Key.');
    }

    public function testRedirectsAreNotFollowedAndUrlsAndSecretsAreRedactedFromErrorsAndLogs(): void
    {
        $clock = new MockClock('2025-06-02 14:05:00 UTC');
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            throw new \RuntimeException('Connection refused for '.$url.' with secret signing-secret-xyz and ops@example.com');
        });

        $result = $this->sender($client, $clock)->send([
            'id' => 'geektown-hub',
            'label' => 'Geektown Hub',
            'url' => 'https://hooks.geektown.example/alerts/secret-hook-path?token=abc',
        ], $this->samplePayload());

        self::assertFalse($result['sent']);
        self::assertStringNotContainsString('secret-hook-path', $result['reason']);
        self::assertStringNotContainsString('signing-secret-xyz', $result['reason']);
        self::assertStringNotContainsString('ops@example.com', $result['reason']);
        self::assertStringNotContainsString('secret-hook-path', (string) json_encode($this->logs));
        self::assertStringNotContainsString('signing-secret-xyz', (string) json_encode($this->logs));
        self::assertStringNotContainsString('ops@example.com', (string) json_encode($this->logs));

        // 302 redirect stops immediately without retrying or following.
        $redirectCalls = 0;
        $redirectClient = new MockHttpClient(function () use (&$redirectCalls): MockResponse {
            ++$redirectCalls;

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://internal.local/']]);
        });
        $redirectResult = $this->sender($redirectClient, $clock)->send([
            'id' => 'geektown-hub',
            'label' => 'Geektown Hub',
            'url' => 'https://hooks.geektown.example/alerts/ingest',
        ], $this->samplePayload());

        self::assertFalse($redirectResult['sent']);
        self::assertSame(1, $redirectCalls);
        self::assertStringContainsString('HTTP 302 (redirects are not followed)', $redirectResult['reason']);
    }

    /**
     * @return array{
     *     schema_version: '1',
     *     id: string,
     *     incident: string,
     *     type: 'firing'|'recovered'|'test',
     *     rule: string,
     *     website: string,
     *     host: string,
     *     installation: string,
     *     alert_condition: string,
     *     window_end: string
     * }
     */
    private function samplePayload(): array
    {
        return [
            'schema_version' => '1',
            'id' => 'a1b2c3d4e5f60718293a4b5c',
            'incident' => '9f8e7d6c5b4a392817263544',
            'type' => 'firing',
            'rule' => 'geektown-weekly',
            'website' => '019c7e21-89ab-7cde-8f01-23456789abcd',
            'host' => 'some.domain.com',
            'installation' => 'https://analytics.example.com',
            'alert_condition' => 'fewer_than_5_per_week',
            'window_end' => '2025-06-02T14:00:00Z',
        ];
    }

    private function sender(MockHttpClient $client, MockClock $clock): WebhookSender
    {
        $logger = new class($this->logs) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        return new WebhookSender(
            $client,
            new AlertSecrets($this->projectDir),
            new AlertRuleValidator(),
            $logger,
            $clock,
            publicAddressesOnly: false,
        );
    }
}
