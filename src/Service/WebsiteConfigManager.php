<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

/**
 * Manages website configurations in config/websites.yaml.
 * Part of the "fallback to YAML" strategy for a simpler headless solution.
 */
class WebsiteConfigManager
{
    private string $configPath;
    private readonly ParsedYamlCache $parsedYaml;
    /** @var array{0: string, 1: array}|null The last registry built, with the hash of the content it came from. */
    private ?array $registry = null;

    public function __construct(
        string $projectDir,
        private readonly WebsiteDomainPolicy $domainPolicy = new WebsiteDomainPolicy(),
        ?ParsedYamlCache $parsedYaml = null,
    ) {
        $this->configPath = $projectDir . '/config/websites.yaml';
        $this->parsedYaml = $parsedYaml ?? ParsedYamlCache::forProject($projectDir);
    }

    /**
     * The registered websites, in file order: name, domain, token and any
     * domain_policy, with defaults filled in.
     */
    public function getWebsites(): array
    {
        return $this->registry()['websites'];
    }

    /** The website with this token, or null when none or several have it. */
    public function findOneByToken(string $token): ?array
    {
        $registry = $this->registry();
        $position = $token === '' ? null : ($registry['tokens'][$token] ?? null);

        return $position === null || $position < 0 ? null : $registry['websites'][$position];
    }

    /**
     * The website whose script id (idForToken()) this is, or null when none
     * or several have it.
     */
    public function findOneById(string $id): ?array
    {
        $registry = $this->registry();
        $position = $registry['ids'][$id] ?? null;

        return $position === null || $position < 0 ? null : $registry['websites'][$position];
    }

    /**
     * Each website that has a token, with its script id, in file order.
     * Websites that share a token are all listed.
     *
     * @return list<array{id: string, name: string, domain: string, token: string}>
     */
    public function sites(): array
    {
        return $this->registry()['sites'];
    }

    /** The id that names a website's script settings and setting files: the start of its token's SHA-256. */
    public static function idForToken(string $token): string
    {
        return substr(hash('sha256', $token), 0, 24);
    }

    /**
     * The registry and its lookups. The file is read on every call, so an
     * edit from another process is seen at once; the lookups are built once
     * per file content and cached beside the parsed YAML, so a lookup does
     * not scan or hash every website.
     *
     * @return array{websites: list<array<string, mixed>>, sites: list<array{id: string, name: string, domain: string, token: string}>, tokens: array<string, int>, ids: array<string, int>}
     */
    private function registry(): array
    {
        $empty = ['websites' => [], 'sites' => [], 'tokens' => [], 'ids' => []];
        $handle = @fopen($this->configPath, 'rb');
        if ($handle === false) {
            return $empty;
        }

        try {
            if (!@flock($handle, LOCK_SH)) {
                return $empty;
            }
            $yaml = @stream_get_contents($handle);
            if (!is_string($yaml)) {
                return $empty;
            }
            $key = hash('xxh128', $yaml);
            if ($this->registry === null || $this->registry[0] !== $key) {
                $this->registry = [$key, $this->parsedYaml->remember('websites-registry', $yaml, fn (string $document): array => self::buildRegistry($this->parseDocument($document)))];
            }

            return $this->registry[1];
        } catch (\Throwable) {
            return $empty;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $config a document parseDocument() accepted
     *
     * @return array{websites: list<array<string, mixed>>, sites: list<array{id: string, name: string, domain: string, token: string}>, tokens: array<string, int>, ids: array<string, int>}
     */
    private static function buildRegistry(array $config): array
    {
        $registry = ['websites' => [], 'sites' => [], 'tokens' => [], 'ids' => []];
        foreach ($config['websites'] ?? [] as $site) {
            $website = [
                'name' => $site['name'] ?? 'Unnamed',
                'domain' => $site['domain'] ?? '',
                'token' => $site['token'] ?? ($site['public_token'] ?? ''),
            ];
            // Keep an explicitly malformed policy intact so ingestion
            // rejects it instead of reverting to the legacy domain rule.
            if (array_key_exists('domain_policy', $site)) {
                $website['domain_policy'] = $site['domain_policy'];
            }
            $position = count($registry['websites']);
            $registry['websites'][] = $website;
            if ($website['token'] === '') {
                continue;
            }
            $id = self::idForToken($website['token']);
            $registry['sites'][] = ['id' => $id, 'name' => $website['name'], 'domain' => $website['domain'], 'token' => $website['token']];
            // -1 marks a token or id that several websites share: neither selects one.
            $registry['tokens'][$website['token']] = isset($registry['tokens'][$website['token']]) ? -1 : $position;
            $registry['ids'][$id] = isset($registry['ids'][$id]) ? -1 : $position;
        }

        return $registry;
    }

    public function addWebsite(string $name, string $domain, ?string $token = null, ?array $domainPolicy = null): bool
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Website name is required.');
        }
        $domain = $this->domainPolicy->normalizePrimaryDomain($domain);
        $policy = $domainPolicy === null ? null : $this->domainPolicy->normalize($domainPolicy);
        if ($token === null || $token === '') {
            $token = bin2hex(random_bytes(16));
        }
        if (strlen($token) > 191 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new \InvalidArgumentException('A website token must contain at most 191 characters without spaces or control characters.');
        }

        return $this->updateDocument(static function (array $config) use ($name, $domain, $token, $policy): array {
            foreach ($config['websites'] ?? [] as $site) {
                if (($site['token'] ?? $site['public_token'] ?? '') === $token) {
                    throw new \InvalidArgumentException('A website with this token already exists. Website tokens must be unique.');
                }
            }
            $site = [
                'name' => $name,
                'domain' => $domain,
                'token' => $token,
            ];
            if ($policy !== null) {
                $site['domain_policy'] = $policy;
            }
            $config['websites'][] = $site;

            return $config;
        });
    }

    public function updateDomainPolicy(string $token, array $policy): bool
    {
        $policy = $this->domainPolicy->normalize($policy);

        return $this->updateDocument(static function (array $config) use ($token, $policy): ?array {
            foreach ($config['websites'] ?? [] as $index => $site) {
                if ($token !== '' && ($site['token'] ?? $site['public_token'] ?? '') === $token) {
                    $config['websites'][$index]['domain_policy'] = $policy;

                    return $config;
                }
            }

            return null;
        });
    }

    public function removeWebsite(string $token): bool
    {
        return $this->updateDocument(static function (array $config) use ($token): ?array {
            foreach ($config['websites'] ?? [] as $index => $site) {
                if ($token !== '' && ($site['token'] ?? $site['public_token'] ?? '') === $token) {
                    unset($config['websites'][$index]);
                    $config['websites'] = array_values($config['websites']);

                    return $config;
                }
            }

            return null;
        });
    }

    /** Read, modify and write the same locked file, retaining symlinks and permissions. */
    private function updateDocument(callable $update): bool
    {
        // c+b creates only a missing file; it never truncates an existing one.
        $handle = @fopen($this->configPath, 'c+b');
        if ($handle === false) {
            return false;
        }
        try {
            if (!@flock($handle, LOCK_EX)) {
                return false;
            }
            $original = @stream_get_contents($handle);
            if (!is_string($original)) {
                return false;
            }
            $config = $this->parseDocument($original);
            $tokens = [];
            foreach ($config['websites'] ?? [] as $site) {
                $token = $site['token'] ?? $site['public_token'] ?? '';
                if ($token !== '' && in_array($token, $tokens, true)) {
                    throw new \InvalidArgumentException('Duplicate website tokens must be corrected in config/websites.yaml before editing registrations.');
                }
                $tokens[] = $token;
            }
            $updated = $update($config);
            if ($updated === null) {
                return false;
            }
            $yaml = Yaml::dump($updated, 6, 2);
            if (!$this->replaceLockedContents($handle, $yaml)) {
                $this->replaceLockedContents($handle, $original);

                return false;
            }

            return true;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable) {
            return false;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function parseDocument(string $yaml): array
    {
        // Empty and comments-only files are valid starting configurations;
        // explicit scalar/null YAML and malformed structures are not.
        $content = preg_replace('/^\s*#.*$/m', '', $yaml);
        $config = trim((string) $content) === '' ? [] : Yaml::parse($yaml);
        if (!is_array($config) || ($config !== [] && array_is_list($config))) {
            throw new \RuntimeException('Website configuration must be a YAML mapping.');
        }
        if (array_key_exists('websites', $config)
            && (!is_array($config['websites']) || !array_is_list($config['websites']))) {
            throw new \RuntimeException('Website registrations must be a YAML list.');
        }
        foreach ($config['websites'] ?? [] as $site) {
            if (!is_array($site) || ($site !== [] && array_is_list($site))) {
                throw new \RuntimeException('Each website registration must be a YAML mapping.');
            }
            foreach (['name', 'domain', 'token', 'public_token'] as $field) {
                if (array_key_exists($field, $site) && !is_string($site[$field])) {
                    throw new \RuntimeException('Website registration fields must be strings.');
                }
            }
        }

        return $config;
    }

    /** @param resource $handle */
    private function replaceLockedContents($handle, string $contents): bool
    {
        if (!@rewind($handle) || !@ftruncate($handle, 0)) {
            return false;
        }
        $length = strlen($contents);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($handle, substr($contents, $offset));
            if (!is_int($written) || $written < 1) {
                return false;
            }
            $offset += $written;
        }

        return @fflush($handle) && (!function_exists('fsync') || @fsync($handle));
    }
}
