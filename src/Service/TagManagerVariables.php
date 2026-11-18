<?php

declare(strict_types=1);

namespace App\Service;

use App\Entities\TagManager\TagVariables as EntityTagVariables;

/** Validate bounded data paths and URL templates without evaluating JavaScript. */
final class TagManagerVariables
{
    public const MAX_VARIABLES = EntityTagVariables::MAX_VARIABLES;
    public const MAX_PATH_LENGTH = EntityTagVariables::MAX_PATH_LENGTH;
    public const MAX_SEGMENTS = EntityTagVariables::MAX_SEGMENTS;
    public const MAX_INDEX = EntityTagVariables::MAX_INDEX;

    /** @return array<string, string> */
    public static function validate(mixed $variables): array
    {
        return EntityTagVariables::validate($variables);
    }

    public static function normalizeSource(string $src): string
    {
        return EntityTagVariables::normalizeSource($src);
    }

    public static function validateSource(mixed $src, array $variables): string
    {
        return EntityTagVariables::validateSource($src, $variables);
    }
}
