<?php

declare(strict_types=1);

namespace App\Service\Alerts;

use App\Service\Email\TransactionalMailer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface as SymfonyClock;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Delivers signed generic JSON v1 webhooks to HTTPS public addresses without
 * following redirects. Each alert is sent as a single-element JSON array with
 * X-Aggregate-Signature (`t=<unix>,v1=<HMAC-SHA256("t.body")>`) and
 * Idempotency-Key (`<alert.id>`).
 *
 * Makes up to MAX_ATTEMPTS attempts within the run, waiting RETRY_DELAYS
 * between them, and only retries what can succeed later: no answer, a 5xx,
 * 408, 425 or 429. Each attempt is signed with a fresh timestamp and keeps the
 * same alert id. An alert that still fails is retried at the next hourly check
 * by AlertEvaluator. Full webhook URLs and secrets are redacted from every
 * error message and log entry.
 *
 * @phpstan-type AlertPayload array{
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
class WebhookSender
{
    public const TIMEOUT = 5.0;
    public const MAX_RESPONSE_BYTES = 4096;
    public const MAX_ATTEMPTS = 3;
    /** Seconds to wait before the second and third attempts. */
    public const RETRY_DELAYS = [1, 3];
    public const USER_AGENT = 'Alert-Webhook/1';
    private const RETRYABLE_STATUS = [408, 425, 429];

    private readonly HttpClientInterface $httpClient;
    private readonly ClockInterface $clock;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly AlertSecrets $secrets,
        private readonly AlertRuleValidator $validator,
        private readonly LoggerInterface $logger,
        ?ClockInterface $clock = null,
        bool $publicAddressesOnly = true,
    ) {
        $this->httpClient = $publicAddressesOnly ? new NoPrivateNetworkHttpClient($httpClient) : $httpClient;
        $this->clock = $clock ?? Clock::get();
    }

    /**
     * Sends one alert payload to a webhook destination, retrying up to
     * MAX_ATTEMPTS times.
     *
     * @param array{id: string, label: string, url: string, host?: string} $destination
     * @param AlertPayload                                                 $payload
     *
     * @return array{sent: bool, status_code: ?int, attempts: int, reason: string}
     */
    public function send(array $destination, array $payload): array
    {
        $url = $destination['url'];
        $destinationId = $destination['id'];
        $secret = null;

        try {
            $url = $this->validator->validateWebhookUrl($url);
        } catch (\InvalidArgumentException $e) {
            return [
                'sent' => false,
                'status_code' => null,
                'attempts' => 0,
                'reason' => 'Not sent: '.self::redact($e->getMessage(), [$url]),
            ];
        }

        try {
            $secret = $this->secrets->get($destinationId);
        } catch (\RuntimeException $e) {
            return [
                'sent' => false,
                'status_code' => null,
                'attempts' => 0,
                'reason' => 'Not sent: '.self::redact($e->getMessage(), [$url]),
            ];
        }

        if ($secret === null || $secret === '') {
            return [
                'sent' => false,
                'status_code' => null,
                'attempts' => 0,
                'reason' => 'Not sent: no webhook signing secret is set in '.AlertSecrets::FILE.'.',
            ];
        }

        $body = json_encode([$payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $lastStatus = null;
        $lastError = 'no answer from the receiver';
        $answered = false;
        $attempts = 0;

        while ($attempts < self::MAX_ATTEMPTS) {
            if ($attempts > 0) {
                $this->wait(self::RETRY_DELAYS[$attempts - 1]);
            }
            ++$attempts;
            $timestamp = $this->clock->now()->getTimestamp();
            $signature = self::sign($timestamp, $body, $secret);

            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json, text/plain, */*',
                        'User-Agent' => self::USER_AGENT,
                        'X-Aggregate-Signature' => sprintf('t=%d,v1=%s', $timestamp, $signature),
                        'Idempotency-Key' => $payload['id'],
                    ],
                    'body' => $body,
                    'timeout' => self::TIMEOUT,
                    'max_duration' => self::TIMEOUT,
                    'max_redirects' => 0,
                    'buffer' => false,
                ]);

                $statusCode = $response->getStatusCode();
                $lastStatus = $statusCode;
                $answered = true;
                $readBytes = 0;
                foreach ($this->httpClient->stream($response) as $chunk) {
                    $readBytes += strlen($chunk->getContent());
                    if ($readBytes > self::MAX_RESPONSE_BYTES) {
                        $response->cancel();
                        break;
                    }
                }

                if ($statusCode >= 200 && $statusCode < 300) {
                    return [
                        'sent' => true,
                        'status_code' => $statusCode,
                        'attempts' => $attempts,
                        'reason' => sprintf('HTTP %d (id %s)', $statusCode, $payload['id']),
                    ];
                }

                if ($statusCode >= 300 && $statusCode < 400) {
                    $lastError = sprintf('the receiver answered HTTP %d (redirects are not followed)', $statusCode);
                    break;
                }

                $lastError = sprintf('the receiver answered HTTP %d', $statusCode);
                if ($statusCode < 500 && !in_array($statusCode, self::RETRYABLE_STATUS, true)) {
                    break; // A 4xx such as 401 or 404 will not change on retry.
                }
            } catch (\Throwable $e) {
                if (preg_match('/^(?:Host|IP) "[^"]*" is blocked for /', $e->getMessage()) === 1) {
                    $lastError = 'the destination resolved to a private or reserved network address';
                    break;
                }
                if ($lastStatus === null) {
                    $answered = false;
                }
                $lastError = self::redact($e->getMessage(), [$url, $secret]);
            }
        }

        $error = rtrim($lastError, '.');
        if ($attempts < 2) {
            $reason = sprintf('Failed: %s.', $error);
        } elseif ($answered) {
            $reason = sprintf('Failed after %d tries: %s.', $attempts, $error);
        } else {
            $reason = sprintf('No answer after %d tries: %s.', $attempts, $error);
        }
        $reason = self::redact($reason, [$url, $secret]);

        $this->logger->warning('An alert webhook delivery failed.', [
            'destination' => $destinationId,
            'rule' => $payload['rule'],
            'type' => $payload['type'],
            'error' => $reason,
        ]);

        return [
            'sent' => false,
            'status_code' => $lastStatus,
            'attempts' => $attempts,
            'reason' => $reason,
        ];
    }

    private function wait(int $seconds): void
    {
        if ($this->clock instanceof SymfonyClock) {
            $this->clock->sleep($seconds);

            return;
        }
        sleep($seconds);
    }

    public static function sign(int $timestamp, string $body, #[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Redacts full URLs, secrets, and email addresses from error text.
     *
     * @param list<string|null> $values
     */
    public static function redact(string $text, array $values = [], int $maxLength = 500): string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $text = str_ireplace($value, '[redacted]', $text);
            }
        }
        // Strip any remaining http(s) URLs so path/query tokens never leak.
        $text = (string) preg_replace('#https?://[^\s"\'<>)\]]+#i', '[webhook-url]', $text);

        return TransactionalMailer::redact($text, $values, $maxLength, true);
    }
}
