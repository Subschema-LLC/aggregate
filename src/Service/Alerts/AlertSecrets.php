<?php

declare(strict_types=1);

namespace App\Service\Alerts;

use App\Service\Operations\ConfigurationChanges;
use App\Service\SecretFileEncryption;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Webhook signing secrets in config/secrets/alerts.yaml (an operator path
 * updates never replace and releases never ship).
 *
 * A shared secret is kept under webhook_secret (or ALERTS_WEBHOOK_SECRET in
 * the environment), and a destination-specific secret under webhook_<id> (or
 * ALERTS_WEBHOOK_SECRET_<ID> in the environment). Values are never shown again
 * once saved, and never written to logs, task details or the audit trail: the
 * audit trail records only which key changed (ConfigurationChanges).
 */
class AlertSecrets
{
    public const FILE = 'config/secrets/alerts.yaml';
    public const DEFAULT_KEY = 'webhook_secret';
    public const ENV_DEFAULT = 'ALERTS_WEBHOOK_SECRET';
    public const MAX_LENGTH = 4096;
    public const SOURCE_ENVIRONMENT = 'environment';
    public const SOURCE_FILE = 'file';

    public function __construct(private readonly string $projectDir)
    {
    }

    public function path(): string
    {
        return $this->projectDir.'/'.self::FILE;
    }

    /**
     * The signing secret for a destination (or the installation-wide default),
     * from the environment or the file; null when neither has it.
     */
    public function get(?string $destinationId = null): ?string
    {
        if ($destinationId !== null && trim($destinationId) !== '') {
            $env = self::environment(self::envNameFor($destinationId));
            if ($env !== null) {
                return $env;
            }
            $file = $this->read();
            $key = self::keyFor($destinationId);
            if (isset($file[$key])) {
                return $file[$key];
            }
            if (isset($file[$destinationId])) {
                return $file[$destinationId];
            }
        }

        $env = self::environment(self::ENV_DEFAULT);
        if ($env !== null) {
            return $env;
        }

        return $this->read()[self::DEFAULT_KEY] ?? null;
    }

    /** Where the secret comes from: SOURCE_ENVIRONMENT, SOURCE_FILE or null when it is not set. */
    public function source(?string $destinationId = null): ?string
    {
        if ($destinationId !== null && trim($destinationId) !== '') {
            if (self::environment(self::envNameFor($destinationId)) !== null) {
                return self::SOURCE_ENVIRONMENT;
            }
            try {
                $file = $this->read();
                $key = self::keyFor($destinationId);
                if (isset($file[$key]) || isset($file[$destinationId])) {
                    return self::SOURCE_FILE;
                }
            } catch (\RuntimeException) {
                return null;
            }
        }

        if (self::environment(self::ENV_DEFAULT) !== null) {
            return self::SOURCE_ENVIRONMENT;
        }

        try {
            return isset($this->read()[self::DEFAULT_KEY]) ? self::SOURCE_FILE : null;
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function isEnvironmentControlled(?string $destinationId = null): bool
    {
        if ($destinationId !== null && trim($destinationId) !== '' && self::environment(self::envNameFor($destinationId)) !== null) {
            return true;
        }

        return self::environment(self::ENV_DEFAULT) !== null;
    }

    /** Stores a webhook signing secret in the file, replacing the previous value. */
    public function set(#[\SensitiveParameter] string $value, ?string $destinationId = null): void
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || !mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException(sprintf('The webhook signing secret must be one line of at most %d characters.', self::MAX_LENGTH));
        }
        $key = $destinationId !== null && trim($destinationId) !== '' ? self::keyFor($destinationId) : self::DEFAULT_KEY;
        $this->update(static function (array $secrets) use ($key, $destinationId, $value): array {
            if ($destinationId !== null && $destinationId !== $key) {
                unset($secrets[$destinationId]);
            }
            $secrets[$key] = $value;

            return $secrets;
        }, $key);
    }

    /** Generates a new 32-byte hex signing secret, stores it in the file and returns it to show once. */
    public function generate(?string $destinationId = null): string
    {
        $secret = bin2hex(random_bytes(32));
        $this->set($secret, $destinationId);

        return $secret;
    }

    /** Removes a secret from the file; the environment, if it sets one, is unaffected. */
    public function remove(?string $destinationId = null): void
    {
        if (!is_file($this->path())) {
            return;
        }
        $key = $destinationId !== null && trim($destinationId) !== '' ? self::keyFor($destinationId) : self::DEFAULT_KEY;
        $this->update(static function (array $secrets) use ($key, $destinationId): array {
            unset($secrets[$key]);
            if ($destinationId !== null) {
                unset($secrets[$destinationId]);
            }

            return $secrets;
        }, $key);
    }

    /**
     * All secrets in the file, for redaction and validation.
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException when the file cannot be read or is invalid
     */
    public function read(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents) || strlen($contents) > 131072) {
            throw new \RuntimeException(self::FILE.' could not be read.');
        }
        $contents = SecretFileEncryption::decode($contents, self::FILE);
        if (strlen($contents) > 65536) {
            throw new \RuntimeException(self::FILE.' could not be read.');
        }
        try {
            $document = Yaml::parse($contents);
        } catch (ParseException) {
            throw new \RuntimeException(self::FILE.' is not valid YAML.');
        }
        if ($document === null) {
            return [];
        }
        if (!is_array($document)) {
            throw new \RuntimeException(self::FILE.' must be a mapping of secret names to values.');
        }
        $secrets = [];
        foreach ($document as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $key) !== 1) {
                throw new \RuntimeException(self::FILE.' must be a mapping of secret names to values.');
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value) || strlen($value) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \RuntimeException(sprintf('%s: %s must be one quoted line of text.', self::FILE, $key));
            }
            $secrets[$key] = trim($value);
        }

        return $secrets;
    }

    public static function keyFor(string $destinationId): string
    {
        $normalized = (string) preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($destinationId)));

        return substr('webhook_'.$normalized, 0, 63);
    }

    public static function envNameFor(string $destinationId): string
    {
        $normalized = (string) preg_replace('/[^A-Z0-9_]/', '_', strtoupper(trim($destinationId)));

        return 'ALERTS_WEBHOOK_SECRET_'.$normalized;
    }

    private static function environment(string $variable): ?string
    {
        foreach ([$_ENV, $_SERVER] as $source) {
            $value = $source[$variable] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param callable(array<string, string>): array<string, string> $change */
    private function update(callable $change, string $key): void
    {
        $directory = dirname($this->path());
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('config/secrets could not be created. Make the config directory writable by the application, or set the secret in the environment.');
        }
        $lock = @fopen($directory.'/.alerts.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('config/secrets is not writable by the application. Make it writable, or set the secret in the environment.');
        }
        @chmod($directory.'/.alerts.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException(self::FILE.' could not be locked.');
            }
            $before = $this->read();
            $after = $change($before);
            if ($after === $before) {
                return;
            }
            ksort($after);
            $this->write($after);
            ConfigurationChanges::record(self::FILE, [$key]);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, string> $secrets */
    private function write(array $secrets): void
    {
        $yaml = "# Alert webhook signing secrets: see docs/ALERTS.md. Written by the Alerts page;\n"
            ."# ALERTS_WEBHOOK_SECRET takes precedence. Keep this file private.\n"
            .($secrets === [] ? "{}\n" : Yaml::dump($secrets, 1, 2));
        $payload = SecretFileEncryption::encode($yaml, self::FILE);
        $directory = dirname($this->path());
        $temporary = @tempnam($directory, '.alerts-');
        if ($temporary === false) {
            throw new \RuntimeException('config/secrets is not writable by the application.');
        }
        try {
            @chmod($temporary, 0600);
            if (@file_put_contents($temporary, $payload) !== strlen($payload) || !@rename($temporary, $this->path())) {
                throw new \RuntimeException(self::FILE.' could not be written.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
