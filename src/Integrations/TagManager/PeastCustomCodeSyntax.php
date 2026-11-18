<?php

declare(strict_types=1);

namespace App\Integrations\TagManager;

use App\Entities\TagManager\CustomCodeSyntax;
use App\Service\TagManagerCustomCode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Validates custom JavaScript syntax and safety via TagManagerCustomCode with filesystem caching.
 */
final class PeastCustomCodeSyntax implements CustomCodeSyntax
{
    public function __construct(
        #[Autowire('%kernel.cache_dir%/tag-manager')]
        private readonly ?string $cacheDirectory = null,
    ) {
    }

    public function validateSyntax(mixed $code, string $path): void
    {
        TagManagerCustomCode::validate($code, $path, $this->cacheDirectory);
    }
}
