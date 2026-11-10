<?php

declare(strict_types=1);

namespace App\Service\Alerts;

use App\Service\Email\TransactionalMailer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Delivers signed generic JSON v1 webhooks to HTTPS public addresses without
 * following redirects. Each alert is sent as a single-element JSON array with
 * X-Aggregate-Signature (`t=<unix>,v1=<HMAC-SHA256("t.body")>`) and
 * Idempotency-Key (`<alert.id>`).
 *
 * Retries up to MAX_ATTEMPTS times within the run, signing each attempt with a
 * fresh timestamp while keeping the same alert id. Full webhook URLs and
 * secrets are redacted from every error message and log entry.
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
        $url = (string) ($destination['url'] ?? '');
        $destinationId = (string) ($destination['id'] ?? '');
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
        $lastError = 'No answer from receiver.';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            $timestamp = $this->clock->now()->getTimestamp();
            $signature = self::sign($timestamp, $body, $secret);

            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json, text/plain, */*',
                        'User-Agent' => 'Aggregate-Webhook/1',
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
                $readBytes = 0;
                foreach ($this->httpClient->stream($response) as $chunk) {
                    $readBytes += strlen($chunk->getContent(false));
                    if ($readBytes > self::MAX_RESPONSE_BYTES) {
                        $response->cancel();
                        break;
                    }
                }

                if ($statusCode >= 200 && $statusCode < 300) {
                    return [
                        'sent' => true,
                        'status_code' => $statusCode,
                        'attempts' => $attempt,
                        'reason' => sprintf('HTTP %d (id %s)', $statusCode, $payload['id']),
                    ];
                }

                if ($statusCode >= 300 && $statusCode < 400) {
                    $lastError = sprintf('the receiver answered HTTP %d (redirects are not followed)', $statusCode);
                    break;
                }

                $lastError = sprintf('the receiver answered HTTP %d', $statusCode);
            } catch (\Throwable $e) {
                $lastError = self::redact($e->getMessage(), [$url, $secret]);
                if (str_contains(strtolower($lastError), 'private') || str_contains(strtolower($lastError), 'reserved') || str_contains(strtolower($lastError), 'blocked')) {
                    $lastError = 'destination resolved to a private or reserved network address';
                    break;
                }
            }
        }

        $reason = self::MAX_ATTEMPTS > 1 && ($attempt ?? 1) >= self::MAX_ATTEMPTS
            ? sprintf('No answer after %d tries: %s.', self::MAX_ATTEMPTS, rtrim($lastError, '.'))
            : sprintf('Failed: %s.', rtrim($lastError, '.'));
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
            'attempts' => min($attempt ?? self::MAX_ATTEMPTS, self::MAX_ATTEMPTS),
            'reason' => $reason,
        ];
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
