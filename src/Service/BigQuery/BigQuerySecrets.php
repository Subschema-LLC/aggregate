<?php

declare(strict_types=1);

namespace App\Service\BigQuery;

/**
 * Credential files for BigQuery sync, kept in config/secrets (preserved by
 * updates, never published) with owner-only permissions:
 *
 * - the service account key (bigquery_credentials_file, by default
 *   config/secrets/bigquery-service-account.json);
 * - config/secrets/bigquery-google-sign-in.json: the OAuth client secret
 *   unless BIGQUERY_OAUTH_CLIENT_SECRET provides it, and the refresh token
 *   and account of the administrator who signed in with Google.
 */
final class BigQuerySecrets
{
    public const SIGN_IN_FILE = 'config/secrets/bigquery-google-sign-in.json';
    public const CLIENT_SECRET_ENV = 'BIGQUERY_OAUTH_CLIENT_SECRET';

    public function __construct(
        private readonly BigQuerySettings $settings,
        private readonly string $projectDir,
    ) {
    }

    /** The configured key, or null when no key file exists. @throws \InvalidArgumentException for an unreadable or invalid key */
    public function serviceAccountKey(): ?ServiceAccountKey
    {
        $path = $this->settings->credentialsPath();
        if (!is_file($path)) {
            return null;
        }
        $json = @file_get_contents($path, false, null, 0, ServiceAccountKey::MAX_BYTES + 1);
        if (!is_string($json)) {
            throw new \InvalidArgumentException('The service account key file exists but cannot be read by the application.');
        }

        return ServiceAccountKey::fromJson($json);
    }

    /** Validates and stores a key at the default path. Returns the parsed key. */
    public function storeServiceAccountKey(#[\SensitiveParameter] string $json): ServiceAccountKey
    {
        if (!$this->settings->canStoreKeyFile()) {
            throw new \InvalidArgumentException('The key file location is set in YAML or the environment. Replace the key file on the server instead.');
        }
        $key = ServiceAccountKey::fromJson(trim($json));
        $this->write($this->projectDir.'/'.BigQuerySettings::DEFAULT_CREDENTIALS_FILE, trim($json)."\n");

        return $key;
    }

    public function removeServiceAccountKey(): void
    {
        if (!$this->settings->canStoreKeyFile()) {
            throw new \InvalidArgumentException('The key file location is set in YAML or the environment. Remove the key file on the server instead.');
        }
        $path = $this->projectDir.'/'.BigQuerySettings::DEFAULT_CREDENTIALS_FILE;
        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException('The key file could not be removed. Check the permissions of config/secrets.');
        }
    }

    public function clientSecret(): string
    {
        foreach ([$_ENV, $_SERVER] as $source) {
            if (is_string($source[self::CLIENT_SECRET_ENV] ?? null) && $source[self::CLIENT_SECRET_ENV] !== '') {
                return $source[self::CLIENT_SECRET_ENV];
            }
        }

        return (string) ($this->signIn()['client_secret'] ?? '');
    }

    public function clientSecretFromEnvironment(): bool
    {
        foreach ([$_ENV, $_SERVER] as $source) {
            if (is_string($source[self::CLIENT_SECRET_ENV] ?? null) && $source[self::CLIENT_SECRET_ENV] !== '') {
                return true;
            }
        }

        return false;
    }

    public static function assertClientSecret(#[\SensitiveParameter] string $secret): void
    {
        if ($secret === '' || strlen($secret) > 512 || preg_match('/^[\x21-\x7E]+$/D', $secret) !== 1) {
            throw new \InvalidArgumentException('Paste the OAuth client secret exactly as the Google Cloud console shows it.');
        }
    }

    public function storeClientSecret(#[\SensitiveParameter] string $secret): void
    {
        $secret = trim($secret);
        self::assertClientSecret($secret);
        $data = $this->signIn();
        if (($data['client_secret'] ?? null) !== $secret) {
            // A different client cannot use the previous client's refresh token.
            $data = ['client_secret' => $secret];
        }
        $this->writeSignIn($data);
    }

    /** @return array{account: string, connected_at: string}|null */
    public function signInConnection(): ?array
    {
        $data = $this->signIn();
        if (!is_string($data['refresh_token'] ?? null) || $data['refresh_token'] === '') {
            return null;
        }

        return ['account' => (string) ($data['account'] ?? ''), 'connected_at' => (string) ($data['connected_at'] ?? '')];
    }

    public function refreshToken(): ?string
    {
        $token = $this->signIn()['refresh_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function storeSignIn(#[\SensitiveParameter] string $refreshToken, string $clientId, string $account, \DateTimeImmutable $now): void
    {
        $data = $this->signIn();
        $data['refresh_token'] = $refreshToken;
        $data['client_id'] = $clientId;
        $data['account'] = $account;
        $data['connected_at'] = $now->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
        $this->writeSignIn($data);
    }

    /** The client the refresh token was issued to; a token for another client is unusable. */
    public function signInClientId(): string
    {
        return (string) ($this->signIn()['client_id'] ?? '');
    }

    public function clearSignIn(): void
    {
        $data = $this->signIn();
        unset($data['refresh_token'], $data['client_id'], $data['account'], $data['connected_at']);
        $this->writeSignIn($data);
    }

    /** @return array<string, mixed> */
    private function signIn(): array
    {
        $path = $this->projectDir.'/'.self::SIGN_IN_FILE;
        if (!is_file($path)) {
            return [];
        }
        $json = @file_get_contents($path, false, null, 0, 65537);
        try {
            $data = is_string($json) ? json_decode($json, true, 4, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $data = null;
        }
        if (!is_array($data)) {
            throw new \RuntimeException(self::SIGN_IN_FILE.' cannot be read. Remove it and sign in with Google again.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function writeSignIn(array $data): void
    {
        $path = $this->projectDir.'/'.self::SIGN_IN_FILE;
        if ($data === []) {
            if (is_file($path) && !@unlink($path)) {
                throw new \RuntimeException('The Google sign-in file could not be removed. Check the permissions of config/secrets.');
            }

            return;
        }
        $this->write($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** Writes a secret file atomically, readable only by the application's user. */
    private function write(string $path, #[\SensitiveParameter] string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('config/secrets could not be created. Make it writable by the web server user, or place the key file on the server yourself.');
        }
        $temporary = @tempnam($directory, '.bigquery-');
        if ($temporary === false) {
            throw new \RuntimeException('config/secrets is not writable by the web server user. Make it writable, or place the key file on the server yourself.');
        }
        try {
            @chmod($temporary, 0600);
            if (@file_put_contents($temporary, $contents) !== strlen($contents) || !@rename($temporary, $path)) {
                throw new \RuntimeException('The credential file could not be written to config/secrets.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
