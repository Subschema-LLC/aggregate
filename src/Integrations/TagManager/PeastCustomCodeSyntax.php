<?php

declare(strict_types=1);

namespace App\Integrations\TagManager;

use App\Entities\TagManager\CustomCodeSyntax;
use App\Service\TagManagerCustomCode;

/**
 * Validates custom JavaScript syntax and safety via TagManagerCustomCode with filesystem caching.
 */
final class PeastCustomCodeSyntax implements CustomCodeSyntax
{
    /** $cacheDirectory is set in config/services.yaml; null checks without caching. */
    public function __construct(private readonly ?string $cacheDirectory = null)
    {
    }

    public function validateSyntax(mixed $code, string $path): void
    {
        TagManagerCustomCode::validate($code, $path, $this->cacheDirectory);
    }
}
