<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

use App\Service\FeatureFlags;
use App\Service\Operations\ConfigurationChanges;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Yaml\Yaml;

/**
 * Reporting API tokens, in config/secrets/api-tokens.yaml (an operator path
 * updates never replace), shared by the dashboard, the app:api:token:*
 * commands and the API itself:
 *
 *     tokens:
 *         3f9a1c2e:
 *             name: 'Power BI refresh'
 *             hash: 'sha256:<64 hex digits>'
 *             views: ~            # every approved view; or a list
 *             private_views: []   # row-level or unsuppressed views, one by one
 *             created_at: '2026-10-18T22:30:00+00:00'
 *             created_by: scott
 *             expires_at: '2027-10-18T22:30:00+00:00'   # or ~ for never
 *             mcp: false          # true: AI assistants may also use it (docs/MCP.md)
 *
 * A token is agg_<id>_<secret>: the ID is public (lists, logs, the audit
 * trail); the secret is 32 random bytes. Only the SHA-256 hash of the whole
 * token is stored, so a lost token can be replaced but not recovered. A slow
 * password hash is not needed for a 256-bit random secret.
 *
 * The file is written atomically, readable only by the application's user,
 * under a lock so two writers cannot lose each other's tokens. An invalid
 * file authenticates nothing until it is corrected, and nothing is saved over it.
 */
class ApiTokenStore
{
    public const FILE = 'config/secrets/api-tokens.yaml';
    public const PREFIX = 'agg_';
    public const MAX_TOKENS = 100;
    public const MAX_NAME = 80;
    public const MAX_BYTES = 262144;
    /** Expiry presets the dashboard offers beside a date of the administrator's choice. */
    public const EXPIRY_CHOICES = ['30d' => '30 days', '90d' => '90 days', '1y' => '1 year', '2y' => '2 years', 'never' => 'Never (not recommended)'];
    public const DEFAULT_EXPIRY = '1y';
    public const NEVER_EXPIRES_WARNING = 'This token never expires. If it leaks, it works until someone revokes it. Prefer an expiry and replace the token before it lapses.';

    private const ID = '/^[a-f0-9]{8}$/D';
    private const TOKEN = '/^agg_([a-f0-9]{8})_([A-Za-z0-9_-]{43})$/D';
    private const HASH = '/^sha256:[a-f0-9]{64}$/D';
    private const KEYS = ['name', 'hash', 'views', 'private_views', 'created_at', 'created_by', 'expires_at', 'mcp'];
    /** Flags that let tokens be created: the reporting API, the MCP server, or both. */
    public const FEATURES = ['reporting_api', 'mcp_server'];

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly string $projectDir,
        private readonly FeatureFlags $features,
        private readonly ReportingApiSettings $settings,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? Clock::get();
    }

    public function path(): string
    {
        return $this->projectDir.'/'.self::FILE;
    }

    /**
     * Every token, by ID, in the order they were created.
     *
     * @return array<string, ApiToken>
     *
     * @throws \RuntimeException when the file cannot be read or is invalid
     */
    public function tokens(): array
    {
        return $this->parse($this->readFile());
    }

    public function find(string $id): ?ApiToken
    {
        return $this->tokens()[$id] ?? null;
    }

    /** Whether tokens can be created: the reporting API or the MCP server is on. */
    public function creatable(): bool
    {
        foreach (self::FEATURES as $feature) {
            if ($this->features->isEnabled($feature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The token a caller presented, or null when it is malformed, unknown,
     * revoked or expired. The caller is not told which.
     *
     * @throws \RuntimeException when the file cannot be read or is invalid
     */
    public function authenticate(#[\SensitiveParameter] string $presented): ?ApiToken
    {
        $hash = self::hash($presented);
        if (preg_match(self::TOKEN, $presented, $matches) !== 1) {
            return null;
        }
        $token = $this->tokens()[$matches[1]] ?? null;
        if ($token === null || !hash_equals($token->hash, $hash) || $token->isExpired($this->clock->now())) {
            return null;
        }

        return $token;
    }

    /**
     * Creates a token and returns it with the secret token, which is shown
     * once and never stored.
     *
     * @param list<string>|null $views        approved views, or null for every approved view
     * @param list<string>      $privateViews private views
     * @param bool              $mcp          whether AI assistants may also use the token on the MCP server
     *
     * @return array{0: ApiToken, 1: string}
     *
     * @throws \InvalidArgumentException for an invalid name, scope or expiry, or an MCP grant while the MCP server is off
     * @throws \RuntimeException         when both the API and the MCP server are off, or the file cannot be written
     */
    public function create(string $name, ?array $views, array $privateViews, ?\DateTimeImmutable $expiresAt, string $createdBy, bool $mcp = false): array
    {
        if (!$this->creatable()) {
            throw new \RuntimeException('The requested feature is disabled or its configuration is invalid.');
        }
        if ($mcp && !$this->features->isEnabled('mcp_server')) {
            throw new \InvalidArgumentException('The MCP server is turned off (mcp_server in Feature flags). Turn it on before creating a token for AI assistants.');
        }
        $name = self::name($name);
        [$views, $privateViews] = ViewScope::validate($views, $privateViews);
        if ($views === [] && $privateViews === []) {
            throw new \InvalidArgumentException('Choose at least one view the token may read.');
        }
        if ($privateViews !== [] && !$this->settings->privateViewsAllowed()) {
            throw new \InvalidArgumentException('Private views are turned off for the reporting API ('.ReportingApiSettings::PRIVATE_VIEWS.'). Turn them on before creating a token that reads '.implode(', ', $privateViews).'.');
        }
        $now = $this->clock->now();
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new \InvalidArgumentException('The expiry must be in the future.');
        }
        $createdBy = trim($createdBy) === '' ? 'unknown' : mb_substr(trim($createdBy), 0, 180);
        if (preg_match('/\p{C}/u', $createdBy) === 1) {
            $createdBy = 'unknown';
        }

        $created = null;
        $secret = '';
        $this->update(function (array $tokens) use ($name, $views, $privateViews, $expiresAt, $createdBy, $now, $mcp, &$created, &$secret): array {
            if (count($tokens) >= self::MAX_TOKENS) {
                throw new \InvalidArgumentException('At most '.self::MAX_TOKENS.' API tokens can be kept. Revoke unused tokens first.');
            }
            foreach ($tokens as $token) {
                if (strcasecmp($token->name, $name) === 0) {
                    throw new \InvalidArgumentException(sprintf('A token is already named "%s".', $name));
                }
            }
            // A numeric-looking ID (all digits, or 12e45678) would change type as a YAML or PHP key.
            do {
                $id = bin2hex(random_bytes(4));
            } while (isset($tokens[$id]) || is_numeric($id));
            $secret = self::PREFIX.$id.'_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $created = new ApiToken($id, $name, self::hash($secret), $views, $privateViews, self::second($now), $createdBy, $expiresAt === null ? null : self::second($expiresAt), $mcp);
            $tokens[$id] = $created;

            return $tokens;
        });
        \assert($created instanceof ApiToken);

        return [$created, $secret];
    }

    /**
     * Deletes a token; it stops working at once. Works while the API is off,
     * so a token can always be revoked.
     *
     * @throws \InvalidArgumentException when there is no such token
     */
    public function revoke(string $id): ApiToken
    {
        $revoked = null;
        $this->update(function (array $tokens) use ($id, &$revoked): array {
            if (!isset($tokens[$id])) {
                throw new \InvalidArgumentException(sprintf('There is no API token with the ID "%s".', $id));
            }
            $revoked = $tokens[$id];
            unset($tokens[$id]);

            return $tokens;
        });
        \assert($revoked instanceof ApiToken);

        return $revoked;
    }

    /**
     * The expiry for a choice: a duration of any length (30d, 12w, 6m, 1y,
     * 25y), a date (YYYY-MM-DD, which expires at the start of that UTC day)
     * or "never". There is no upper limit; a token that never expires is
     * allowed, and the dashboard and the command warn about it.
     *
     * @throws \InvalidArgumentException for anything else, or a time that is not in the future
     */
    public function expiryFrom(string $choice): ?\DateTimeImmutable
    {
        $choice = strtolower(trim($choice));
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        if ($choice === 'never') {
            return null;
        }
        $expiry = null;
        if (preg_match('/^([1-9][0-9]{0,5})([dwmy])$/D', $choice, $matches) === 1) {
            $unit = ['d' => 'days', 'w' => 'weeks', 'm' => 'months', 'y' => 'years'][$matches[2]];
            $expiry = $now->modify('+'.$matches[1].' '.$unit);
        } elseif (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $choice, $matches) === 1 && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            $expiry = new \DateTimeImmutable($choice.'T00:00:00+00:00');
        }
        if (!$expiry instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('Choose an expiry such as 30d, 90d or 1y, a date (YYYY-MM-DD), or "never".');
        }
        // Dates are written with a four-digit year.
        if ((int) $expiry->format('Y') > 9999) {
            throw new \InvalidArgumentException('Choose an expiry before the year 10000, or "never".');
        }
        if ($expiry <= $now) {
            throw new \InvalidArgumentException('The expiry must be in the future.');
        }

        return $expiry;
    }

    private static function hash(#[\SensitiveParameter] string $token): string
    {
        return 'sha256:'.hash('sha256', $token);
    }

    private static function second(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'));
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/\p{C}/u', $name) === 1) {
            throw new \InvalidArgumentException('Give the token a name of at most '.self::MAX_NAME.' characters, without control characters.');
        }

        return $name;
    }

    private function readFile(): string
    {
        $path = $this->path();
        if (!is_file($path)) {
            return '';
        }
        if (filesize($path) > self::MAX_BYTES) {
            throw new \RuntimeException(self::FILE.' is too large.');
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException(self::FILE.' cannot be read by the application.');
        }

        return $contents;
    }

    /**
     * @return array<string, ApiToken>
     *
     * @throws \RuntimeException naming what is wrong, never a hash
     */
    private function parse(string $yaml): array
    {
        if (trim((string) preg_replace('/^\s*(?:#.*|---|\.\.\.)\s*$/m', '', $yaml)) === '') {
            return [];
        }
        try {
            $data = Yaml::parse($yaml);
        } catch (\Throwable) {
            throw new \RuntimeException(self::FILE.' is not valid YAML.');
        }
        try {
            if (!is_array($data) || array_diff(array_keys($data), ['tokens']) !== []) {
                throw new \InvalidArgumentException('it may only contain tokens.');
            }
            $raw = $data['tokens'] ?? [];
            if (!is_array($raw) || ($raw !== [] && array_is_list($raw)) || count($raw) > self::MAX_TOKENS) {
                throw new \InvalidArgumentException('tokens must be a mapping of at most '.self::MAX_TOKENS.' tokens.');
            }
            $tokens = [];
            foreach ($raw as $id => $entry) {
                $id = (string) $id;
                try {
                    $tokens[$id] = $this->token($id, $entry);
                } catch (\InvalidArgumentException $error) {
                    throw new \InvalidArgumentException(sprintf('token "%s": %s', $id, $error->getMessage()));
                }
            }
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException(self::FILE.' is invalid: '.$error->getMessage());
        }

        return $tokens;
    }

    private function token(string $id, mixed $entry): ApiToken
    {
        if (preg_match(self::ID, $id) !== 1) {
            throw new \InvalidArgumentException('the ID must be 8 lowercase hexadecimal characters.');
        }
        if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
            throw new \InvalidArgumentException('it must be a mapping.');
        }
        $unknown = array_diff(array_keys($entry), self::KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('unknown setting "%s".', (string) reset($unknown)));
        }
        if (!is_string($entry['hash'] ?? null) || preg_match(self::HASH, $entry['hash']) !== 1) {
            throw new \InvalidArgumentException('hash must be "sha256:" followed by 64 hexadecimal digits.');
        }
        if (!array_key_exists('views', $entry)) {
            throw new \InvalidArgumentException('views must be ~ (every approved view) or a list.');
        }
        [$views, $privateViews] = ViewScope::validate($entry['views'], $entry['private_views'] ?? []);
        $mcp = $entry['mcp'] ?? false;
        if (!is_bool($mcp)) {
            throw new \InvalidArgumentException('mcp must be true or false.');
        }
        $createdAt = self::time($entry['created_at'] ?? null, 'created_at') ?? throw new \InvalidArgumentException('created_at is required.');

        return new ApiToken(
            $id,
            self::name(is_string($entry['name'] ?? null) ? $entry['name'] : ''),
            $entry['hash'],
            $views,
            $privateViews,
            $createdAt,
            is_scalar($entry['created_by'] ?? null) ? (string) $entry['created_by'] : '',
            self::time($entry['expires_at'] ?? null, 'expires_at'),
            $mcp,
        );
    }

    private static function time(mixed $value, string $key): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        // An unquoted timestamp in a hand-edited file is parsed as seconds.
        if (is_int($value)) {
            return (new \DateTimeImmutable('@'.$value))->setTimezone(new \DateTimeZone('UTC'));
        }
        if (is_string($value) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}(?:[T ][0-9]{2}:[0-9]{2}(?::[0-9]{2})?(?:Z|[+-][0-9]{2}:?[0-9]{2})?)?$/D', trim($value)) === 1) {
            try {
                return new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC'));
            } catch (\Exception) {
            }
        }

        throw new \InvalidArgumentException($key.' must be an ISO 8601 date and time.');
    }

    /**
     * Rereads the file under an exclusive lock, applies the change and writes
     * the result atomically, readable only by the application's user.
     *
     * @param callable(array<string, ApiToken>): array<string, ApiToken> $change
     */
    private function update(callable $change): void
    {
        $directory = dirname($this->path());
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('config/secrets could not be created. Make the config directory writable by the application.');
        }
        $lock = @fopen($directory.'/.api-tokens.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('config/secrets is not writable by the application.');
        }
        @chmod($directory.'/.api-tokens.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException(self::FILE.' could not be locked.');
            }
            $before = $this->tokens();
            $after = $change($before);
            $this->write($after);
            // IDs come from the tokens, not array keys: an all-digit ID is an integer key.
            ConfigurationChanges::record(self::FILE, array_values(array_map(
                static fn (ApiToken $token): string => 'tokens.'.$token->id,
                array_diff_key($before, $after) + array_diff_key($after, $before),
            )));
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, ApiToken> $tokens */
    private function write(array $tokens): void
    {
        $document = [];
        foreach ($tokens as $token) {
            $document[$token->id] = [
                'name' => $token->name,
                'hash' => $token->hash,
                'views' => $token->views,
                'private_views' => $token->privateViews,
                'created_at' => $token->createdAt->format(\DateTimeInterface::ATOM),
                'created_by' => $token->createdBy,
                'expires_at' => $token->expiresAt?->format(\DateTimeInterface::ATOM),
                'mcp' => $token->mcp,
            ];
        }
        $yaml = "# Reporting API tokens: see docs/REPORTING-API.md. Written by the dashboard and\n"
            ."# app:api:token:create. Only hashes are stored; a lost token can be replaced, not recovered.\n"
            .Yaml::dump(['tokens' => $document === [] ? new \ArrayObject() : $document], 4, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_NULL_AS_TILDE | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $directory = dirname($this->path());
        $temporary = @tempnam($directory, '.api-tokens-');
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
