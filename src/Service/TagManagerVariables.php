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

    /**
     * A URL copied out of an HTML snippet keeps its `&amp;` separators, which a
     * browser then requests literally, so the tracker reads `amp;token` instead
     * of `token`. A literal entity is never a meaningful query separator, so
     * every entry point stores and serves the plain `&` instead.
     */
    public static function normalizeSource(string $src): string
    {
        return EntityTagVariables::normalizeSource($src);
    }

    public static function validateSource(mixed $src, array $variables): string
    {
        return EntityTagVariables::validateSource($src, $variables);
    }
}
