<?php

declare(strict_types=1);

namespace App\Features\TagManager\TagForm;

use App\Entities\TagManager\TagVariables;

/**
 * Extracts and validates variable rows from the tag manager form.
 */
final class TagFormVariables
{
    /**
     * @param mixed $rows
     * @return array<string, string>
     */
    public static function extract(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)
            || count($rows) > TagVariables::MAX_VARIABLES
        ) {
            throw new \InvalidArgumentException(
                'Variables must be a list of aliases and data paths.',
            );
        }
        $variables = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || array_diff(array_keys($row), ['alias', 'path', 'remove']) !== []
                || !is_string($row['alias'] ?? null) || !is_string($row['path'] ?? null)
                || (array_key_exists('remove', $row) && $row['remove'] !== '1')
            ) {
                throw new \InvalidArgumentException(
                    'Each variable needs an alias and a data path.',
                );
            }
            if (($row['remove'] ?? null) === '1'
                || ($row['alias'] === '' && $row['path'] === '')
            ) {
                continue;
            }
            if (array_key_exists($row['alias'], $variables)) {
                throw new \InvalidArgumentException('Each variable alias must be unique.');
            }
            $variables[$row['alias']] = $row['path'];
        }

        return $variables;
    }
}
