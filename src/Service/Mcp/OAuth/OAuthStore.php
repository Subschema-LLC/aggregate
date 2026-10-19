<?php

declare(strict_types=1);

namespace App\Service\Mcp\OAuth;

use App\Service\Mcp\McpConnection;
use App\Service\Operations\ConfigurationChanges;
use App\Service\ReportingApi\ViewScope;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Yaml\Yaml;

/**
 * AI assistant sign-in state, in config/secrets/mcp-oauth.yaml (an operator
 * path updates never replace), shared by the sign-in endpoints, the MCP
 * server, the API tokens page and the app:mcp:connection:* commands:
 *
 *     clients:                     # registered by assistants (RFC 7591)
 *         aggc_0123456789abcdef:
 *             name: 'Claude'
 *             redirect_uris: ['https://claude.ai/api/mcp/auth_callback']
 *             created_at: '2026-10-19T02:00:00+00:00'
 *     codes:                       # approved sign-ins waiting for exchange
 *         3f9a1c2e:
 *             hash: 'sha256:<64 hex digits>'
 *             client_id: 'aggc_0123456789abcdef'
 *             client_name: 'Claude'
 *             redirect_uri: 'https://claude.ai/api/mcp/auth_callback'
 *             code_challenge: '<43 characters>'
 *             views: ~
 *             private_views: []
 *             approved_by: scott
 *             connection_expires_at: '2027-01-17T02:00:00+00:00'
 *             expires_at: '2026-10-19T02:01:00+00:00'
 *     connections:                 # approved assistants
 *         8b7c6d5e:
 *             client_id: 'aggc_0123456789abcdef'
 *             client_name: 'Claude'
 *             views: ~             # every approved view; or a list
 *             private_views: []
 *             approved_by: scott
 *             created_at: '2026-10-19T02:00:30+00:00'
 *             expires_at: '2027-01-17T02:00:00+00:00'   # or ~ for never
 *             access_hash: 'sha256:<64 hex digits>'
 *             access_expires_at: '2026-10-19T03:00:30+00:00'
 *             refresh_hash: 'sha256:<64 hex digits>'
 *             previous_refresh_hash: ~
 *             rotated_at: ~
 *
 * Tokens are <prefix><id>_<secret>, the secret 32 random bytes; only SHA-256
 * hashes are stored, as for reporting API tokens. A code lives 60 seconds and
 * works once. An access token lives an hour; renewing it with the refresh
 * token replaces both, so the file is written about once an hour per
 * connected assistant. A refresh token presented again after it was replaced
 * revokes the connection, unless it is a retry within ROTATION_GRACE seconds.
 *
 * Every write rereads the file under an exclusive lock, drops expired codes
 * and connections and clients nothing uses after a day, and writes the result
 * atomically, readable only by the application's user. An invalid file
 * authenticates nothing until it is corrected, and nothing is saved over it.
 */
class OAuthStore
{
    public const FILE = 'config/secrets/mcp-oauth.yaml';
    public const ACCESS_PREFIX = 'aggmcp_';
    public const REFRESH_PREFIX = 'aggmcpr_';
    public const CODE_PREFIX = 'aggcode_';
    public const CLIENT_PREFIX = 'aggc_';
    public const CODE_LIFETIME = 60;
    public const ACCESS_LIFETIME = 3600;
    public const ROTATION_GRACE = 30;
    public const CLIENT_GRACE = 86400;
    public const MAX_CONNECTIONS = 100;
    public const MAX_CLIENTS = 200;
    public const MAX_CODES = 100;
    public const MAX_BYTES = 1048576;
    public const MAX_CLIENT_NAME = 100;
    public const MAX_REDIRECT_URIS = 10;
    /** The expiry the approval page suggests for a connection. */
    public const DEFAULT_EXPIRY = '90d';

    private const ID = '/^[a-f0-9]{8}$/D';
    private const CLIENT_ID = '/^aggc_[a-f0-9]{16}$/D';
    private const HASH = '/^sha256:[a-f0-9]{64}$/D';
    private const CHALLENGE = '/^[A-Za-z0-9_-]{43}$/D';
    private const VERIFIER = '/^[A-Za-z0-9._~-]{43,128}$/D';
    private const SECTIONS = ['clients', 'codes', 'connections'];
    private const CLIENT_KEYS = ['name', 'redirect_uris', 'created_at'];
    private const CODE_KEYS = ['hash', 'client_id', 'client_name', 'redirect_uri', 'code_challenge', 'views', 'private_views', 'approved_by', 'connection_expires_at', 'expires_at'];
    private const CONNECTION_KEYS = ['client_id', 'client_name', 'views', 'private_views', 'approved_by', 'created_at', 'expires_at', 'access_hash', 'access_expires_at', 'refresh_hash', 'previous_refresh_hash', 'rotated_at'];

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly string $projectDir,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? Clock::get();
    }

    public function path(): string
    {
        return $this->projectDir.'/'.self::FILE;
    }

    /**
     * Every connection, by ID, in the order they were approved.
     *
     * @return array<string, McpConnection>
     *
     * @throws \RuntimeException when the file cannot be read or is invalid
     */
    public function connections(): array
    {
        return $this->read()['connections'];
    }

    public function findConnection(string $id): ?McpConnection
    {
        return $this->connections()[$id] ?? null;
    }

    /** A client registered here, or null. @throws \RuntimeException when the file is invalid */
    public function client(string $clientId): ?OAuthClient
    {
        $client = $this->read()['clients'][$clientId] ?? null;

        return $client === null ? null : new OAuthClient($clientId, $client['name'], $client['redirect_uris'], OAuthClient::REGISTERED);
    }

    /**
     * The connection an access token belongs to, or null when the token is
     * malformed, unknown, replaced or expired, or the connection is revoked
     * or expired. The caller is not told which.
     *
     * @throws \RuntimeException when the file cannot be read or is invalid
     */
    public function authenticate(#[\SensitiveParameter] string $presented): ?McpConnection
    {
        $id = self::idOf($presented, self::ACCESS_PREFIX);
        $hash = self::hash($presented);
        if ($id === null) {
            return null;
        }
        $connection = $this->connections()[$id] ?? null;
        $now = $this->clock->now();
        if ($connection === null || !hash_equals($connection->accessHash, $hash) || $connection->accessExpiresAt <= $now || $connection->isExpired($now)) {
            return null;
        }

        return $connection;
    }

    /**
     * Registers a public client (RFC 7591) and returns it.
     *
     * @param array<mixed> $redirectUris as the client sent them, checked here
     *
     * @throws OAuthError invalid_redirect_uri or invalid_client_metadata, or temporarily_unavailable when too many are registered
     */
    public function registerClient(string $name, array $redirectUris): OAuthClient
    {
        $name = trim($name);
        if (mb_strlen($name) > self::MAX_CLIENT_NAME || preg_match('/\p{C}/u', $name) === 1) {
            throw new OAuthError('invalid_client_metadata', 'client_name must be at most '.self::MAX_CLIENT_NAME.' characters, without control characters.');
        }
        if ($redirectUris === [] || !array_is_list($redirectUris) || count($redirectUris) > self::MAX_REDIRECT_URIS) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must list 1 to '.self::MAX_REDIRECT_URIS.' redirect URIs.');
        }
        $uris = [];
        foreach ($redirectUris as $uri) {
            try {
                $uris[RedirectUri::assertValid($uri)] = true;
            } catch (\InvalidArgumentException $error) {
                throw new OAuthError('invalid_redirect_uri', $error->getMessage());
            }
        }
        $client = null;
        $this->update(function (array $data) use ($name, $uris, &$client): array {
            if (count($data['clients']) >= self::MAX_CLIENTS) {
                throw new OAuthError('temporarily_unavailable', 'Too many assistant clients are registered on this installation. Try again tomorrow, or ask an administrator.', 503);
            }
            do {
                $id = self::CLIENT_PREFIX.bin2hex(random_bytes(8));
            } while (isset($data['clients'][$id]));
            $data['clients'][$id] = ['name' => $name === '' ? 'Unnamed assistant' : $name, 'redirect_uris' => array_keys($uris), 'created_at' => $this->now()];
            $client = new OAuthClient($id, $data['clients'][$id]['name'], $data['clients'][$id]['redirect_uris'], OAuthClient::REGISTERED);

            return $data;
        });
        \assert($client instanceof OAuthClient);

        return $client;
    }

    /**
     * Records an administrator's approval and returns the one-time code the
     * assistant exchanges for tokens.
     *
     * @param list<string>|null $views
     * @param list<string>      $privateViews
     *
     * @throws \InvalidArgumentException for an invalid scope or expiry
     */
    public function issueCode(OAuthClient $client, string $redirectUri, string $codeChallenge, ?array $views, array $privateViews, ?\DateTimeImmutable $connectionExpiresAt, string $approvedBy): string
    {
        if (preg_match(self::CHALLENGE, $codeChallenge) !== 1) {
            throw new \InvalidArgumentException('The code challenge must be an S256 challenge of 43 characters.');
        }
        if (!$client->allowsRedirect($redirectUri)) {
            throw new \InvalidArgumentException('The redirect URI is not registered for this assistant.');
        }
        [$views, $privateViews] = ViewScope::validate($views, $privateViews);
        if ($views === [] && $privateViews === []) {
            throw new \InvalidArgumentException('Choose at least one view the assistant may read.');
        }
        $now = $this->now();
        if ($connectionExpiresAt !== null && $connectionExpiresAt <= $now) {
            throw new \InvalidArgumentException('The expiry must be in the future.');
        }
        $approvedBy = self::actor($approvedBy);
        $code = '';
        $this->update(function (array $data) use ($client, $redirectUri, $codeChallenge, $views, $privateViews, $connectionExpiresAt, $approvedBy, $now, &$code): array {
            if (count($data['codes']) >= self::MAX_CODES) {
                throw new \RuntimeException('Too many sign-ins are waiting to finish. Try again in a minute.');
            }
            $id = self::newId($data['codes']);
            $code = self::CODE_PREFIX.$id.'_'.self::secret();
            $data['codes'][$id] = [
                'hash' => self::hash($code),
                'client_id' => $client->id,
                'client_name' => $client->name,
                'redirect_uri' => $redirectUri,
                'code_challenge' => $codeChallenge,
                'views' => $views,
                'private_views' => $privateViews,
                'approved_by' => $approvedBy,
                'connection_expires_at' => $connectionExpiresAt === null ? null : self::second($connectionExpiresAt),
                'expires_at' => $now->modify('+'.self::CODE_LIFETIME.' seconds'),
            ];

            return $data;
        });

        return $code;
    }

    /**
     * Exchanges a code for a new connection's tokens. The code is used up
     * whatever the outcome.
     *
     * @return array{0: McpConnection, 1: string, 2: string} the connection, access token and refresh token
     *
     * @throws OAuthError invalid_grant
     */
    public function exchangeCode(#[\SensitiveParameter] string $code, string $clientId, string $redirectUri, #[\SensitiveParameter] string $codeVerifier): array
    {
        $id = self::idOf($code, self::CODE_PREFIX);
        if ($id === null) {
            throw new OAuthError('invalid_grant', 'The authorization code is not valid.');
        }
        $failure = null;
        $issued = null;
        $this->update(function (array $data) use ($id, $code, $clientId, $redirectUri, $codeVerifier, &$failure, &$issued): array {
            $entry = $data['codes'][$id] ?? null;
            unset($data['codes'][$id]);
            $now = $this->now();
            $failure = match (true) {
                $entry === null, !hash_equals($entry['hash'], self::hash($code)), $entry['expires_at'] <= $now => 'The authorization code is not valid. It may have expired or been used already.',
                !hash_equals($entry['client_id'], $clientId) => 'The authorization code was issued to another client.',
                !hash_equals($entry['redirect_uri'], $redirectUri) => 'redirect_uri does not match the one the code was issued for.',
                preg_match(self::VERIFIER, $codeVerifier) !== 1 || !hash_equals($entry['code_challenge'], self::challenge($codeVerifier)) => 'The code verifier does not match the code challenge.',
                $entry['connection_expires_at'] !== null && $entry['connection_expires_at'] <= $now => 'The approval has expired.',
                count($data['connections']) >= self::MAX_CONNECTIONS => 'At most '.self::MAX_CONNECTIONS.' assistant connections can be kept. An administrator needs to revoke unused connections first.',
                default => null,
            };
            if ($failure !== null) {
                return $data;
            }
            $connectionId = self::newId($data['connections']);
            [$access, $refresh] = [self::ACCESS_PREFIX.$connectionId.'_'.self::secret(), self::REFRESH_PREFIX.$connectionId.'_'.self::secret()];
            $data['connections'][$connectionId] = new McpConnection(
                $connectionId,
                $entry['client_id'],
                $entry['client_name'],
                $entry['views'],
                $entry['private_views'],
                $entry['approved_by'],
                $now,
                $entry['connection_expires_at'],
                self::hash($access),
                $now->modify('+'.self::ACCESS_LIFETIME.' seconds'),
                self::hash($refresh),
                null,
            );
            $issued = [$data['connections'][$connectionId], $access, $refresh];

            return $data;
        });
        if ($failure !== null || $issued === null) {
            throw new OAuthError('invalid_grant', $failure ?? 'The authorization code is not valid.');
        }

        return $issued;
    }

    /**
     * Replaces a connection's access and refresh tokens.
     *
     * @return array{0: McpConnection, 1: string, 2: string} the connection, access token and refresh token
     *
     * @throws OAuthError invalid_grant
     */
    public function refresh(#[\SensitiveParameter] string $refreshToken, string $clientId): array
    {
        $id = self::idOf($refreshToken, self::REFRESH_PREFIX);
        if ($id === null) {
            throw new OAuthError('invalid_grant', 'The refresh token is not valid.');
        }
        $failure = null;
        $issued = null;
        $this->update(function (array $data) use ($id, $refreshToken, $clientId, &$failure, &$issued): array {
            $connection = $data['connections'][$id] ?? null;
            $now = $this->now();
            $hash = self::hash($refreshToken);
            if ($connection === null || $connection->isExpired($now) || !hash_equals($connection->clientId, $clientId)) {
                $failure = 'The refresh token is not valid. The connection may have been revoked or have expired.';

                return $data;
            }
            $retry = $connection->previousRefreshHash !== null && hash_equals($connection->previousRefreshHash, $hash);
            if (!hash_equals($connection->refreshHash, $hash) && !$retry) {
                $failure = 'The refresh token is not valid.';

                return $data;
            }
            $rotatedAt = $data['rotated_at'][$id] ?? null;
            if ($retry && ($rotatedAt === null || $rotatedAt->modify('+'.self::ROTATION_GRACE.' seconds') < $now)) {
                // A replaced refresh token came back: it may have been copied.
                unset($data['connections'][$id]);
                $failure = 'The refresh token was already used. The connection has been revoked; sign in again to reconnect.';

                return $data;
            }
            [$access, $refresh] = [self::ACCESS_PREFIX.$id.'_'.self::secret(), self::REFRESH_PREFIX.$id.'_'.self::secret()];
            $data['connections'][$id] = new McpConnection(
                $id,
                $connection->clientId,
                $connection->clientName,
                $connection->views,
                $connection->privateViews,
                $connection->approvedBy,
                $connection->createdAt,
                $connection->expiresAt,
                self::hash($access),
                $now->modify('+'.self::ACCESS_LIFETIME.' seconds'),
                self::hash($refresh),
                // A retry keeps the token it retried with as the one a second retry may use.
                $retry ? $connection->previousRefreshHash : $connection->refreshHash,
            );
            $data['rotated_at'][$id] = $retry ? $rotatedAt : $now;
            $issued = [$data['connections'][$id], $access, $refresh];

            return $data;
        });
        if ($failure !== null || $issued === null) {
            throw new OAuthError('invalid_grant', $failure ?? 'The refresh token is not valid.');
        }

        return $issued;
    }

    /**
     * Deletes a connection; its tokens stop working at once. Works while the
     * MCP server is off, so a connection can always be revoked.
     *
     * @throws \InvalidArgumentException when there is no such connection
     */
    public function revoke(string $id): McpConnection
    {
        $revoked = null;
        $this->update(function (array $data) use ($id, &$revoked): array {
            if (!isset($data['connections'][$id])) {
                throw new \InvalidArgumentException(sprintf('There is no assistant connection with the ID "%s".', $id));
            }
            $revoked = $data['connections'][$id];
            unset($data['connections'][$id]);

            return $data;
        });
        \assert($revoked instanceof McpConnection);

        return $revoked;
    }

    /**
     * Token revocation (RFC 7009): revokes the connection an access or refresh
     * token belongs to, when the token is current. Says nothing either way.
     *
     * @return McpConnection|null the revoked connection
     */
    public function revokeToken(#[\SensitiveParameter] string $token, string $clientId): ?McpConnection
    {
        $id = self::idOf($token, self::ACCESS_PREFIX) ?? self::idOf($token, self::REFRESH_PREFIX);
        if ($id === null) {
            return null;
        }
        $revoked = null;
        $this->update(function (array $data) use ($id, $token, $clientId, &$revoked): array {
            $connection = $data['connections'][$id] ?? null;
            $hash = self::hash($token);
            if ($connection !== null && hash_equals($connection->clientId, $clientId)
                && (hash_equals($connection->accessHash, $hash) || hash_equals($connection->refreshHash, $hash))) {
                $revoked = $connection;
                unset($data['connections'][$id]);
            }

            return $data;
        });

        return $revoked;
    }

    /** @return string|null the ID in <prefix><id>_<secret>, or null when the token is malformed */
    private static function idOf(string $token, string $prefix): ?string
    {
        return preg_match('/^'.preg_quote($prefix, '/').'([a-f0-9]{8})_[A-Za-z0-9_-]{43}$/D', $token, $matches) === 1 ? $matches[1] : null;
    }

    private static function hash(#[\SensitiveParameter] string $token): string
    {
        return 'sha256:'.hash('sha256', $token);
    }

    /** The S256 code challenge of a verifier (RFC 7636). */
    public static function challenge(#[\SensitiveParameter] string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $taken */
    private static function newId(array $taken): string
    {
        // A numeric-looking ID (all digits, or 12e45678) would change type as a YAML or PHP key.
        do {
            $id = bin2hex(random_bytes(4));
        } while (isset($taken[$id]) || is_numeric($id));

        return $id;
    }

    private static function actor(string $actor): string
    {
        $actor = trim($actor) === '' ? 'unknown' : mb_substr(trim($actor), 0, 180);

        return preg_match('/\p{C}/u', $actor) === 1 ? 'unknown' : $actor;
    }

    private function now(): \DateTimeImmutable
    {
        return self::second($this->clock->now());
    }

    private static function second(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'));
    }

    /**
     * @return array{clients: array<string, array{name: string, redirect_uris: list<string>, created_at: \DateTimeImmutable}>, codes: array<string, array<string, mixed>>, connections: array<string, McpConnection>, rotated_at: array<string, \DateTimeImmutable|null>}
     *
     * @throws \RuntimeException when the file cannot be read or is invalid
     */
    private function read(): array
    {
        $path = $this->path();
        $yaml = '';
        if (is_file($path)) {
            if (filesize($path) > self::MAX_BYTES) {
                throw new \RuntimeException(self::FILE.' is too large.');
            }
            $contents = @file_get_contents($path);
            if (!is_string($contents)) {
                throw new \RuntimeException(self::FILE.' cannot be read by the application.');
            }
            $yaml = $contents;
        }

        return $this->parse($yaml);
    }

    /**
     * @return array{clients: array<string, array{name: string, redirect_uris: list<string>, created_at: \DateTimeImmutable}>, codes: array<string, array<string, mixed>>, connections: array<string, McpConnection>, rotated_at: array<string, \DateTimeImmutable|null>}
     *
     * @throws \RuntimeException naming what is wrong, never a hash
     */
    private function parse(string $yaml): array
    {
        $empty = ['clients' => [], 'codes' => [], 'connections' => [], 'rotated_at' => []];
        if (trim((string) preg_replace('/^\s*(?:#.*|---|\.\.\.)\s*$/m', '', $yaml)) === '') {
            return $empty;
        }
        try {
            $data = Yaml::parse($yaml);
        } catch (\Throwable) {
            throw new \RuntimeException(self::FILE.' is not valid YAML.');
        }
        try {
            if (!is_array($data) || array_diff(array_map('strval', array_keys($data)), self::SECTIONS) !== []) {
                throw new \InvalidArgumentException('it may only contain clients, codes and connections.');
            }
            $parsed = $empty;
            foreach (['clients' => self::MAX_CLIENTS, 'codes' => self::MAX_CODES, 'connections' => self::MAX_CONNECTIONS] as $section => $maximum) {
                $raw = $data[$section] ?? [];
                if (!is_array($raw) || ($raw !== [] && array_is_list($raw)) || count($raw) > $maximum) {
                    throw new \InvalidArgumentException(sprintf('%s must be a mapping of at most %d entries.', $section, $maximum));
                }
                foreach ($raw as $id => $entry) {
                    $id = (string) $id;
                    try {
                        if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                            throw new \InvalidArgumentException('it must be a mapping.');
                        }
                        match ($section) {
                            'clients' => $parsed['clients'][$id] = self::parseClient($id, $entry),
                            'codes' => $parsed['codes'][$id] = self::parseCode($id, $entry),
                            'connections' => [$parsed['connections'][$id], $parsed['rotated_at'][$id]] = self::parseConnection($id, $entry),
                        };
                    } catch (\InvalidArgumentException $error) {
                        throw new \InvalidArgumentException(sprintf('%s "%s": %s', rtrim($section, 's'), $id, $error->getMessage()));
                    }
                }
            }
        } catch (\InvalidArgumentException $error) {
            throw new \RuntimeException(self::FILE.' is invalid: '.$error->getMessage());
        }

        return $parsed;
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array{name: string, redirect_uris: list<string>, created_at: \DateTimeImmutable}
     */
    private static function parseClient(string $id, array $entry): array
    {
        if (preg_match(self::CLIENT_ID, $id) !== 1) {
            throw new \InvalidArgumentException('the ID must be "aggc_" and 16 lowercase hexadecimal characters.');
        }
        self::assertKeys($entry, self::CLIENT_KEYS);
        $uris = $entry['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || !array_is_list($uris) || count($uris) > self::MAX_REDIRECT_URIS) {
            throw new \InvalidArgumentException('redirect_uris must list 1 to '.self::MAX_REDIRECT_URIS.' redirect URIs.');
        }
        foreach ($uris as $uri) {
            RedirectUri::assertValid($uri);
        }

        return [
            'name' => self::name($entry['name'] ?? null),
            'redirect_uris' => $uris,
            'created_at' => self::time($entry['created_at'] ?? null, 'created_at') ?? throw new \InvalidArgumentException('created_at is required.'),
        ];
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function parseCode(string $id, array $entry): array
    {
        self::assertId($id);
        self::assertKeys($entry, self::CODE_KEYS);
        foreach (['client_id', 'redirect_uri', 'code_challenge', 'approved_by'] as $key) {
            if (!is_string($entry[$key] ?? null) || $entry[$key] === '') {
                throw new \InvalidArgumentException($key.' is required.');
            }
        }
        if (!array_key_exists('views', $entry)) {
            throw new \InvalidArgumentException('views must be ~ (every approved view) or a list.');
        }
        [$views, $privateViews] = ViewScope::validate($entry['views'], $entry['private_views'] ?? []);

        return [
            'hash' => self::hashValue($entry['hash'] ?? null, 'hash'),
            'client_id' => $entry['client_id'],
            'client_name' => self::name($entry['client_name'] ?? null),
            'redirect_uri' => RedirectUri::assertValid($entry['redirect_uri']),
            'code_challenge' => $entry['code_challenge'],
            'views' => $views,
            'private_views' => $privateViews,
            'approved_by' => $entry['approved_by'],
            'connection_expires_at' => self::time($entry['connection_expires_at'] ?? null, 'connection_expires_at'),
            'expires_at' => self::time($entry['expires_at'] ?? null, 'expires_at') ?? throw new \InvalidArgumentException('expires_at is required.'),
        ];
    }

    /**
     * @param array<mixed> $entry
     *
     * @return array{0: McpConnection, 1: \DateTimeImmutable|null}
     */
    private static function parseConnection(string $id, array $entry): array
    {
        self::assertId($id);
        self::assertKeys($entry, self::CONNECTION_KEYS);
        if (!is_string($entry['client_id'] ?? null) || $entry['client_id'] === '') {
            throw new \InvalidArgumentException('client_id is required.');
        }
        if (!array_key_exists('views', $entry)) {
            throw new \InvalidArgumentException('views must be ~ (every approved view) or a list.');
        }
        [$views, $privateViews] = ViewScope::validate($entry['views'], $entry['private_views'] ?? []);
        $previous = $entry['previous_refresh_hash'] ?? null;

        return [new McpConnection(
            $id,
            $entry['client_id'],
            self::name($entry['client_name'] ?? null),
            $views,
            $privateViews,
            is_scalar($entry['approved_by'] ?? null) ? (string) $entry['approved_by'] : '',
            self::time($entry['created_at'] ?? null, 'created_at') ?? throw new \InvalidArgumentException('created_at is required.'),
            self::time($entry['expires_at'] ?? null, 'expires_at'),
            self::hashValue($entry['access_hash'] ?? null, 'access_hash'),
            self::time($entry['access_expires_at'] ?? null, 'access_expires_at') ?? throw new \InvalidArgumentException('access_expires_at is required.'),
            self::hashValue($entry['refresh_hash'] ?? null, 'refresh_hash'),
            $previous === null ? null : self::hashValue($previous, 'previous_refresh_hash'),
        ), self::time($entry['rotated_at'] ?? null, 'rotated_at')];
    }

    private static function assertId(string $id): void
    {
        if (preg_match(self::ID, $id) !== 1) {
            throw new \InvalidArgumentException('the ID must be 8 lowercase hexadecimal characters.');
        }
    }

    /**
     * @param array<mixed> $entry
     * @param list<string> $keys
     */
    private static function assertKeys(array $entry, array $keys): void
    {
        $unknown = array_diff(array_map('strval', array_keys($entry)), $keys);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('unknown setting "%s".', (string) reset($unknown)));
        }
    }

    private static function hashValue(mixed $value, string $key): string
    {
        if (!is_string($value) || preg_match(self::HASH, $value) !== 1) {
            throw new \InvalidArgumentException($key.' must be "sha256:" followed by 64 hexadecimal digits.');
        }

        return $value;
    }

    private static function name(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > self::MAX_CLIENT_NAME || preg_match('/\p{C}/u', $value) === 1) {
            throw new \InvalidArgumentException('the name must be text of at most '.self::MAX_CLIENT_NAME.' characters.');
        }

        return trim($value);
    }

    private static function time(mixed $value, string $key): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
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
     * Rereads the file under an exclusive lock, drops what has expired,
     * applies the change and writes the result atomically.
     *
     * @param callable(array{clients: array<string, array{name: string, redirect_uris: list<string>, created_at: \DateTimeImmutable}>, codes: array<string, array<string, mixed>>, connections: array<string, McpConnection>, rotated_at: array<string, \DateTimeImmutable|null>}): array{clients: array<string, array{name: string, redirect_uris: list<string>, created_at: \DateTimeImmutable}>, codes: array<string, array<string, mixed>>, connections: array<string, McpConnection>, rotated_at: array<string, \DateTimeImmutable|null>} $change
     */
    private function update(callable $change): void
    {
        $directory = dirname($this->path());
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('config/secrets could not be created. Make the config directory writable by the application.');
        }
        $lock = @fopen($directory.'/.mcp-oauth.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('config/secrets is not writable by the application.');
        }
        @chmod($directory.'/.mcp-oauth.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException(self::FILE.' could not be locked.');
            }
            $before = $this->read();
            $after = $change($this->prune($before));
            $this->write($after);
            ConfigurationChanges::record(self::FILE, array_merge(
                array_map(static fn (string $id): string => 'connections.'.$id, array_map('strval', array_keys(array_diff_key($before['connections'], $after['connections']) + array_diff_key($after['connections'], $before['connections'])))),
                array_map(static fn (string $id): string => 'clients.'.$id, array_map('strval', array_keys(array_diff_key($before['clients'], $after['clients']) + array_diff_key($after['clients'], $before['clients'])))),
            ));
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Drops expired codes and connections, and registered clients that no
     * connection or waiting code uses once they are a day old.
     *
     * @param array{clients: array<string, array{name: string, redirect_uris: list<string>, created_at: \DateTimeImmutable}>, codes: array<string, array<string, mixed>>, connections: array<string, McpConnection>, rotated_at: array<string, \DateTimeImmutable|null>} $data
     *
     * @return array{clients: array<string, array{name: string, redirect_uris: list<string>, created_at: \DateTimeImmutable}>, codes: array<string, array<string, mixed>>, connections: array<string, McpConnection>, rotated_at: array<string, \DateTimeImmutable|null>}
     */
    private function prune(array $data): array
    {
        $now = $this->now();
        $data['codes'] = array_filter($data['codes'], static fn (array $code): bool => $code['expires_at'] > $now);
        $data['connections'] = array_filter($data['connections'], static fn (McpConnection $connection): bool => !$connection->isExpired($now));
        $used = [...array_map(static fn (McpConnection $connection): string => $connection->clientId, $data['connections']), ...array_column($data['codes'], 'client_id')];
        $cutoff = $now->modify('-'.self::CLIENT_GRACE.' seconds');
        $data['clients'] = array_filter(
            $data['clients'],
            static fn (array $client, string|int $id): bool => in_array((string) $id, $used, true) || $client['created_at'] > $cutoff,
            ARRAY_FILTER_USE_BOTH,
        );

        return $data;
    }

    /**
     * @param array{clients: array<string, array{name: string, redirect_uris: list<string>, created_at: \DateTimeImmutable}>, codes: array<string, array<string, mixed>>, connections: array<string, McpConnection>, rotated_at: array<string, \DateTimeImmutable|null>} $data
     */
    private function write(array $data): void
    {
        $time = static fn (?\DateTimeImmutable $value): ?string => $value?->format(\DateTimeInterface::ATOM);
        $document = ['clients' => [], 'codes' => [], 'connections' => []];
        foreach ($data['clients'] as $id => $client) {
            $document['clients'][(string) $id] = ['name' => $client['name'], 'redirect_uris' => $client['redirect_uris'], 'created_at' => $time($client['created_at'])];
        }
        foreach ($data['codes'] as $id => $code) {
            $document['codes'][(string) $id] = [
                'hash' => $code['hash'],
                'client_id' => $code['client_id'],
                'client_name' => $code['client_name'],
                'redirect_uri' => $code['redirect_uri'],
                'code_challenge' => $code['code_challenge'],
                'views' => $code['views'],
                'private_views' => $code['private_views'],
                'approved_by' => $code['approved_by'],
                'connection_expires_at' => $time($code['connection_expires_at']),
                'expires_at' => $time($code['expires_at']),
            ];
        }
        foreach ($data['connections'] as $connection) {
            $document['connections'][$connection->id] = [
                'client_id' => $connection->clientId,
                'client_name' => $connection->clientName,
                'views' => $connection->views,
                'private_views' => $connection->privateViews,
                'approved_by' => $connection->approvedBy,
                'created_at' => $time($connection->createdAt),
                'expires_at' => $time($connection->expiresAt),
                'access_hash' => $connection->accessHash,
                'access_expires_at' => $time($connection->accessExpiresAt),
                'refresh_hash' => $connection->refreshHash,
                'previous_refresh_hash' => $connection->previousRefreshHash,
                'rotated_at' => $time($data['rotated_at'][$connection->id] ?? null),
            ];
        }
        foreach ($document as $section => $entries) {
            if ($entries === []) {
                $document[$section] = new \ArrayObject();
            }
        }
        $yaml = "# AI assistant sign-in for the MCP server: see docs/MCP.md. Written by the sign-in\n"
            ."# endpoints, the API tokens page and app:mcp:connection:revoke. Only hashes are stored.\n"
            .Yaml::dump($document, 4, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_NULL_AS_TILDE | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $directory = dirname($this->path());
        $temporary = @tempnam($directory, '.mcp-oauth-');
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
