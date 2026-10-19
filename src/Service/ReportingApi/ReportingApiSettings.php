<?php

declare(strict_types=1);

namespace App\Service\ReportingApi;

use App\Service\AggregateConfigLoader;

/**
 * Deployment settings of the reporting API: one source for the API tokens
 * page, YAML, environment variables and the API itself. Each setting lives in
 * the active aggregate YAML; an uppercase environment variable of the same
 * name takes precedence and locks the dashboard field.
 *
 * - reporting_api_private_views (default false): whether tokens may read
 *   private views at all, the row-level and unsuppressed views
 *   ViewCatalog::PRIVATE lists. While it is off no token can be given a
 *   private view, and the private views listed on existing tokens stay in
 *   their file but are not served. An invalid value counts as off.
 * - reporting_api_max_page_size (default 10000): the most rows one request
 *   may ask for with limit. Responses are streamed, so a larger page costs
 *   time and bandwidth rather than server memory.
 *
 * Whether the API exists at all is the reporting_api feature flag.
 */
class ReportingApiSettings
{
    public const PRIVATE_VIEWS = 'reporting_api_private_views';
    public const MAX_PAGE_SIZE = 'reporting_api_max_page_size';

    public const DEFAULTS = [
        self::PRIVATE_VIEWS => false,
        self::MAX_PAGE_SIZE => 10000,
    ];
    /** The range the page size setting accepts. */
    public const PAGE_SIZE_RANGE = [1, 1000000];
    /** Rows a request returns when it does not ask for a limit, or the maximum when that is smaller. */
    public const DEFAULT_PAGE_SIZE = 100;

    public function __construct(private readonly AggregateConfigLoader $config)
    {
    }

    /**
     * The effective, validated settings.
     *
     * @return array{reporting_api_private_views: bool, reporting_api_max_page_size: int}
     *
     * @throws \InvalidArgumentException for the first invalid value
     * @throws \RuntimeException         when the configuration cannot be read
     */
    public function toArray(): array
    {
        $this->config->assertHealthy();
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = $this->config->getWithEnvFallback($key, $default);
        }

        return self::validate($values);
    }

    /** Whether tokens may read private views; false when the configuration is invalid. */
    public function privateViewsAllowed(): bool
    {
        try {
            return $this->toArray()[self::PRIVATE_VIEWS];
        } catch (\RuntimeException|\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @throws \InvalidArgumentException|\RuntimeException when the configuration is invalid
     */
    public function maxPageSize(): int
    {
        return $this->toArray()[self::MAX_PAGE_SIZE];
    }

    public function defaultPageSize(): int
    {
        return min(self::DEFAULT_PAGE_SIZE, $this->maxPageSize());
    }

    /** @return array<string, bool> setting => set in the server environment */
    public function environmentOverrides(): array
    {
        $overrides = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $overrides[$key] = $this->config->hasEnvironmentOverride($key);
        }

        return $overrides;
    }

    /**
     * Validates the candidate and writes the values the environment does not
     * control. Nothing is written when a value is invalid.
     *
     * @param array<string, mixed> $candidate
     */
    public function save(array $candidate): void
    {
        if (array_diff_key($candidate, self::DEFAULTS) !== []) {
            throw new \InvalidArgumentException('The form contained an unexpected reporting API setting. Nothing was saved.');
        }
        $current = $this->toArray();
        $overrides = $this->environmentOverrides();
        foreach ($overrides as $key => $overridden) {
            if ($overridden) {
                $candidate[$key] = $current[$key];
            }
        }
        $validated = self::validate([...$current, ...$candidate]);
        $values = array_filter($validated, static fn (string $key): bool => !$overrides[$key], ARRAY_FILTER_USE_KEY);
        if ($values !== [] && $values !== array_intersect_key($current, $values)) {
            $this->config->setMany($values);
        }
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{reporting_api_private_views: bool, reporting_api_max_page_size: int}
     */
    public static function validate(array $values): array
    {
        $private = self::boolean($values[self::PRIVATE_VIEWS] ?? null)
            ?? throw new \InvalidArgumentException(self::PRIVATE_VIEWS.' must be true or false.');
        $size = $values[self::MAX_PAGE_SIZE] ?? null;
        if (is_string($size) && preg_match('/^[0-9]{1,7}$/D', trim($size)) === 1) {
            $size = (int) trim($size);
        }
        [$minimum, $maximum] = self::PAGE_SIZE_RANGE;
        if (!is_int($size) || $size < $minimum || $size > $maximum) {
            throw new \InvalidArgumentException(sprintf('%s must be a whole number of rows from %s to %s.', self::MAX_PAGE_SIZE, number_format($minimum), number_format($maximum)));
        }

        return [self::PRIVATE_VIEWS => $private, self::MAX_PAGE_SIZE => $size];
    }

    private static function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => null,
            };
        }

        return null;
    }
}
