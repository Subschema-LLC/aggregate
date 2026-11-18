<?php

declare(strict_types=1);

namespace App\Integrations\TagManager;

use App\Entities\TagManager\CustomCodeSyntax;
use App\Service\TagManagerCustomCode;

/**
 * Validates custom JavaScript syntax and safety via TagManagerCustomCode.
 */
final class PeastCustomCodeSyntax implements CustomCodeSyntax
{
    public function validateSyntax(
        string $code,
        string $path,
        ?string $cacheDirectory = null,
    ): void {
        TagManagerCustomCode::validate($code, $path, $cacheDirectory);
    }
}
