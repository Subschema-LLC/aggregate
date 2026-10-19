<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Service\Operations\ConfigurationChanges;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Email credentials, in config/secrets/email.yaml (an operator path updates
 * never replace and releases never ship), one key per provider so switching
 * providers keeps the other keys:
 *
 *     postmark_server_token: '…'
 *     sendgrid_api_key: '…'
 *     mailgun_api_key: '…'
 *     smtp_password: '…'
 *     dsn: '…'
 *
 * An environment variable (EMAIL_ plus the key in capitals, such as
 * EMAIL_POSTMARK_SERVER_TOKEN) takes precedence over the file and cannot be
 * changed from the dashboard. Values are never shown again once saved, and
 * never written to logs, task details or the audit trail: the audit trail
 * records only which key changed (ConfigurationChanges).
 *
 * The file is written atomically under a lock, readable only by the
 * application's user. An invalid file provides no credentials until it is
 * corrected, and nothing is saved over it.
 */
class EmailSecrets
{
    public const FILE = 'config/secrets/email.yaml';
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

    /** The credential, from the environment or the file; null when neither has it. */
    public function get(EmailCredential $credential): ?string
    {
        $environment = self::environment($credential);
        if ($environment !== null) {
            return $environment;
        }

        return $this->read()[$credential->key] ?? null;
    }

    /** Where the credential comes from: SOURCE_ENVIRONMENT, SOURCE_FILE or null when it is not set. */
    public function source(EmailCredential $credential): ?string
    {
        if (self::environment($credential) !== null) {
            return self::SOURCE_ENVIRONMENT;
        }

        try {
            return isset($this->read()[$credential->key]) ? self::SOURCE_FILE : null;
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function isEnvironmentControlled(EmailCredential $credential): bool
    {
        return self::environment($credential) !== null;
    }

    /** Stores a credential in the file, replacing the previous value. */
    public function set(EmailCredential $credential, #[\SensitiveParameter] string $value): void
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || !mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException(sprintf('The %s must be one line of at most %d characters.', strtolower($credential->label), self::MAX_LENGTH));
        }
        $this->update(static function (array $secrets) use ($credential, $value): array {
            $secrets[$credential->key] = $value;

            return $secrets;
        }, $credential->key);
    }

    /** Removes a credential from the file; the environment, if it sets one, is unaffected. */
    public function remove(EmailCredential $credential): void
    {
        if (!is_file($this->path())) {
            return;
        }
        $this->update(static function (array $secrets) use ($credential): array {
            unset($secrets[$credential->key]);

            return $secrets;
        }, $credential->key);
    }

    /**
     * The credentials in the file.
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
        if (!is_string($contents) || strlen($contents) > 65536) {
            throw new \RuntimeException(self::FILE.' could not be read.');
        }
        try {
            $document = Yaml::parse($contents);
        } catch (ParseException) {
            // The parser's message can quote a line of the file.
            throw new \RuntimeException(self::FILE.' is not valid YAML.');
        }
        if ($document === null) {
            return [];
        }
        if (!is_array($document)) {
            throw new \RuntimeException(self::FILE.' must be a mapping of credential names to values.');
        }
        $secrets = [];
        foreach ($document as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1) {
                throw new \RuntimeException(self::FILE.' must be a mapping of credential names to values.');
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value) || strlen($value) > self::MAX_LENGTH) {
                throw new \RuntimeException(sprintf('%s: %s must be one quoted line of text.', self::FILE, $key));
            }
            $secrets[$key] = trim($value);
        }

        return $secrets;
    }

    private static function environment(EmailCredential $credential): ?string
    {
        foreach ([$_ENV, $_SERVER] as $source) {
            $value = $source[$credential->environmentVariable] ?? null;
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
            throw new \RuntimeException('config/secrets could not be created. Make the config directory writable by the application, or set the credential in the environment.');
        }
        $lock = @fopen($directory.'/.email.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('config/secrets is not writable by the application. Make it writable, or set the credential in the environment.');
        }
        @chmod($directory.'/.email.lock', 0600);
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
        $yaml = "# Email credentials: see docs/EMAIL.md. Written by the Email page; environment\n"
            ."# variables named EMAIL_<KEY> take precedence. Keep this file private.\n"
            .($secrets === [] ? "{}\n" : Yaml::dump($secrets, 1, 2));
        $directory = dirname($this->path());
        $temporary = @tempnam($directory, '.email-');
        if ($temporary === false) {
            throw new \RuntimeException('config/secrets is not writable by the application.');
        }
        try {
            @chmod($temporary, 0600);
            if (@file_put_contents($temporary, $yaml) !== strlen($yaml) || !@rename($temporary, $this->path())) {
                throw new \RuntimeException(self::FILE.' could not be written.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
