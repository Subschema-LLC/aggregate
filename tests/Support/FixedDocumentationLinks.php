<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\AggregateConfigLoader;
use App\Service\DocumentationLinks;

/** DocumentationLinks with a fixed documentation_url, for tests that do not read configuration files. */
final class FixedDocumentationLinks
{
    /** @param string $url the documentation_url value; '' turns links off */
    public static function create(string $url = DocumentationLinks::DEFAULT_URL, bool $environmentOverride = false): DocumentationLinks
    {
        return new DocumentationLinks(new class ($url, $environmentOverride) extends AggregateConfigLoader {
            public function __construct(private readonly string $url, private readonly bool $override)
            {
                parent::__construct(sys_get_temp_dir(), 'test');
            }

            public function getWithEnvFallback(string $key, mixed $default = null, bool $allowEmpty = false): mixed
            {
                return $key === DocumentationLinks::CONFIG_KEY ? $this->url : $default;
            }

            public function hasEnvironmentOverride(string $key, bool $allowEmpty = false): bool
            {
                return $key === DocumentationLinks::CONFIG_KEY && $this->override;
            }
        });
    }
}
