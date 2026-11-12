<?php

declare(strict_types=1);

namespace App\Tests\Migration;

/**
 * Finds schema changes a server on the previous release could not run
 * against: during a cluster update, collection and worker servers still on
 * the old code write to `events` and `messenger_messages` after the
 * administration server has migrated. So on those tables a migration may
 * add a nullable or defaulted column, or an index built without blocking
 * inserts, and nothing else.
 *
 * It reads a migration's SQL strings and Doctrine schema calls; it is a
 * guard for review, not a parser, so a migration it misreads is listed with
 * its reason in MigrationAdditivityTest::REVIEWED.
 */
final class MigrationAdditivity
{
    /** Tables the collection path and the queue handler write. */
    public const TABLES = ['events', 'messenger_messages'];

    /** @return list<string> what in the migration is not additive */
    public static function problems(string $php): array
    {
        // down() never runs on an update (rollback restores files, not the schema).
        $php = self::withoutMethod($php, 'down');
        $problems = [];
        $tables = implode('|', self::TABLES);
        $name = '[`"\[]?(?:'.$tables.')[`"\]]?';
        $createsIndex = false;

        foreach (self::strings($php) as $sql) {
            $sql = (string) preg_replace('/\s+/', ' ', $sql);
            if (preg_match('/\bDROP TABLE (?:IF EXISTS )?'.$name.'(?![\w])/i', $sql) === 1) {
                $problems[] = 'drops the table: '.$sql;
            }
            if (preg_match('/\b(?:RENAME TABLE|sp_rename) \'?'.$name.'(?![\w])/i', $sql) === 1) {
                $problems[] = 'renames the table or a column: '.$sql;
            }
            if (preg_match('/\bCREATE (?:UNIQUE )?INDEX\b.*\bON '.$name.'(?![\w])/i', $sql) === 1) {
                $createsIndex = true;
            }
            if (preg_match('/\bALTER TABLE '.$name.' (.*)$/i', $sql, $match) !== 1) {
                continue;
            }
            foreach (self::clauses($match[1]) as $clause) {
                $problem = self::clauseProblem($clause);
                if ($problem !== null) {
                    $problems[] = $problem.': '.$sql;
                }
            }
        }

        // Doctrine's schema API, on a protected table fetched by name, held in
        // a variable or called directly.
        $targets = [];
        if (preg_match_all('/(\$\w+)\s*=\s*\$\w+->getTable\(\s*[\'"](?:'.$tables.')[\'"]\s*\)/', $php, $variables) > 0) {
            $targets = array_map(static fn (string $variable): string => preg_quote($variable, '/'), $variables[1]);
        }
        $targets[] = '\$\w+->getTable\(\s*[\'"](?:'.$tables.')[\'"]\s*\)';
        $on = '(?:'.implode('|', array_unique($targets)).')';
        foreach (['dropColumn', 'renameColumn', 'modifyColumn', 'changeColumn'] as $call) {
            if (preg_match('/'.$on.'\s*->'.$call.'\(/', $php) === 1) {
                $problems[] = 'calls '.$call.'() on '.implode(' or ', self::TABLES);
            }
        }
        if (preg_match_all('/'.$on.'\s*->addColumn\((.*?)\);/s', $php, $calls) > 0) {
            foreach ($calls[1] as $arguments) {
                if (preg_match('/[\'"]notnull[\'"]\s*=>\s*false/', $arguments) !== 1 && preg_match('/[\'"]default[\'"]\s*=>/', $arguments) !== 1) {
                    $problems[] = 'adds a NOT NULL column without a default (addColumn is NOT NULL unless notnull is false): addColumn('.trim((string) preg_replace('/\s+/', ' ', $arguments)).')';
                }
            }
        }
        if (preg_match('/'.$on.'\s*->(?:addIndex|addUniqueIndex)\(/', $php) === 1) {
            $createsIndex = true;
        }
        if (preg_match('/->dropTable\(\s*[\'"](?:'.$tables.')[\'"]/', $php) === 1) {
            $problems[] = 'drops the table with dropTable()';
        }

        if ($createsIndex) {
            $missing = array_keys(array_filter([
                'CONCURRENTLY (PostgreSQL)' => stripos($php, 'CONCURRENTLY') === false,
                'LOCK=NONE (MySQL and MariaDB)' => preg_match('/LOCK\s*=\s*NONE/i', $php) !== 1,
                'isTransactional() returning false' => preg_match('/function isTransactional\(\)\s*:\s*bool\s*\{\s*return false;/', $php) !== 1,
            ]));
            if ($missing !== []) {
                $problems[] = 'builds an index that may block inserts; it needs '.implode(', ', $missing);
            }
        }

        return array_values(array_unique($problems));
    }

    private static function clauseProblem(string $clause): ?string
    {
        $clause = trim($clause);
        if (preg_match('/^(?:RENAME|CHANGE|MODIFY)\b/i', $clause) === 1) {
            return 'renames or retypes a column';
        }
        if (preg_match('/^ALTER (?:COLUMN )?\S+ (.*)$/i', $clause, $match) === 1) {
            // Relaxing a column is additive: the old code still writes it.
            return preg_match('/^(?:SET DEFAULT\b|DROP NOT NULL$)/i', $match[1]) === 1 ? null : 'changes a column ('.$match[1].')';
        }
        if (preg_match('/^DROP (?:COLUMN )?(?:IF EXISTS )?(?!INDEX\b|KEY\b|CONSTRAINT\b|FOREIGN\b|PRIMARY\b)\S+/i', $clause) === 1) {
            return 'drops a column';
        }
        if (preg_match('/^ADD (?:COLUMN )?(?:IF NOT EXISTS )?(?!INDEX\b|KEY\b|CONSTRAINT\b|UNIQUE\b|PRIMARY\b|FOREIGN\b|FULLTEXT\b|SPATIAL\b)\S+ (.*)$/i', $clause, $match) === 1
            && preg_match('/\bNOT NULL\b/i', $match[1]) === 1 && preg_match('/\bDEFAULT\b/i', $match[1]) !== 1) {
            return 'adds a NOT NULL column without a default';
        }

        return null;
    }

    private static function withoutMethod(string $php, string $method): string
    {
        if (preg_match('/function '.$method.'\s*\(/', $php, $match, \PREG_OFFSET_CAPTURE) !== 1) {
            return $php;
        }
        $start = $match[0][1];
        $open = strpos($php, '{', $start);
        if ($open === false) {
            return $php;
        }
        $depth = 0;
        for ($i = $open, $length = strlen($php); $i < $length; ++$i) {
            $depth += $php[$i] === '{' ? 1 : ($php[$i] === '}' ? -1 : 0);
            if ($depth === 0) {
                return substr($php, 0, $start).substr($php, $i + 1);
            }
        }

        return $php;
    }

    /**
     * The clauses of an ALTER TABLE, split on commas outside parentheses.
     *
     * @return list<string>
     */
    private static function clauses(string $alter): array
    {
        $clauses = [];
        $depth = 0;
        $current = '';
        foreach (str_split($alter) as $character) {
            $depth += $character === '(' ? 1 : ($character === ')' ? -1 : 0);
            if ($character === ',' && $depth === 0) {
                $clauses[] = $current;
                $current = '';
                continue;
            }
            $current .= $character;
        }
        $clauses[] = $current;

        return $clauses;
    }

    /**
     * The string literals in PHP source, quoted and heredoc alike.
     *
     * @return list<string>
     */
    private static function strings(string $php): array
    {
        $strings = [];
        foreach (token_get_all($php) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === \T_CONSTANT_ENCAPSED_STRING) {
                $strings[] = stripcslashes(substr($token[1], 1, -1));
            } elseif ($token[0] === \T_ENCAPSED_AND_WHITESPACE) {
                $strings[] = $token[1];
            }
        }

        return $strings;
    }
}
