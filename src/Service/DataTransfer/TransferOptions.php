<?php

declare(strict_types=1);

namespace App\Service\DataTransfer;

/**
 * Operator choices for a transfer. Validation here rejects names and tokens
 * before they are used in paths, SQL, or commands.
 */
final class TransferOptions
{
    /**
     * Users are included only when the operator asks. A dataset list that
     * names users is not enough on its own.
     *
     * @param list<string>|null $datasets
     *
     * @return list<string>
     */
    public static function tables(?array $datasets, bool $includeUsers): array
    {
        $requested = $datasets === null || $datasets === [] ? ['events', 'archives'] : $datasets;
        $tables = [];
        foreach ($requested as $dataset) {
            if ($dataset === 'users') {
                continue;
            }
            if (!in_array($dataset, ['events', 'archives'], true)) {
                throw new DataTransferException('Choose events, archives, or users.');
            }
            foreach (TransferCatalog::datasetTables($dataset) as $table) {
                $tables[$table] = $table;
            }
        }
        if ($includeUsers) {
            $tables['users'] = 'users';
        }
        if ($tables === []) {
            throw new DataTransferException('Choose events, archives, or users.');
        }

        return array_values($tables);
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    public static function tokens(array $tokens): array
    {
        $clean = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $token) !== 1) {
                throw new DataTransferException('A website token filter is not a token this installation can collect.');
            }
            $clean[$token] = $token;
        }
        if (count($clean) > 200) {
            throw new DataTransferException('Too many website tokens were listed.');
        }

        return array_values($clean);
    }

    /**
     * @param list<string> $pairs old=new pairs. The new token must be collectable; the old one is only matched.
     *
     * @return array<string, string>
     */
    public static function tokenMap(array $pairs): array
    {
        $map = [];
        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $equals = strpos($pair, '=');
            if ($equals === false || $equals === 0) {
                throw new DataTransferException('A token mapping must be written as old=new.');
            }
            $old = substr($pair, 0, $equals);
            $new = substr($pair, $equals + 1);
            if (preg_match('/^[\x20-\x7E]{1,191}$/D', $old) !== 1 || preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $new) !== 1) {
                throw new DataTransferException('A mapped website token is not a token this installation can collect.');
            }
            if (isset($map[$old])) {
                throw new DataTransferException('A website token is mapped more than once.');
            }
            $map[$old] = $new;
        }
        if (count($map) > 200) {
            throw new DataTransferException('Too many website tokens were mapped.');
        }

        return $map;
    }

    public static function packageName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $name) !== 1) {
            throw new DataTransferException('The package name must be letters, numbers, hyphens or underscores.');
        }

        return $name;
    }

    public static function onConflict(string $value): string
    {
        if (!in_array($value, ['skip', 'replace', 'fail'], true)) {
            throw new DataTransferException('Choose skip, replace, or fail for rows that already exist.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value)) {
            throw new DataTransferException('A transfer option was not a list of values.');
        }
        $list = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new DataTransferException('A transfer option was not a list of values.');
            }
            $list[] = $item;
        }

        return $list;
    }
}
