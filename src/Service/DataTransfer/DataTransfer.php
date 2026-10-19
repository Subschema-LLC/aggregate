<?php

declare(strict_types=1);

namespace App\Service\DataTransfer;

use App\Service\ProjectDirEnvVarProcessor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Copies collected rows between installations. Configuration stays in YAML
 * and is not copied. Anonymous rows that carry an identifier or exact detail
 * are refused rather than rewritten.
 */
final class DataTransfer
{
    public const TASK_TYPE = 'data_transfer';
    public const TASK_SUBJECT = 'transfer';
    public const LOCK_SECONDS = 86400;
    public const TARGET_URL_ENV = 'AGGREGATE_TRANSFER_TARGET_URL';

    private const BATCH_ROWS = 50;
    private const BATCH_BYTES = 1_000_000;

    /** @var array<string, string> */
    private const SCHEME_MAP = [
        'mssql' => 'pdo_sqlsrv',
        'mysql' => 'pdo_mysql',
        'mysql2' => 'pdo_mysql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
        'sqlite' => 'pdo_sqlite',
        'sqlite3' => 'pdo_sqlite',
    ];

    public function __construct(
        private readonly Connection $application,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param list<string>|null $datasets
     * @param list<string> $websiteTokens
     */
    public function export(
        string $packageName,
        ?array $datasets,
        array $websiteTokens,
        bool $includeUsers,
        bool $consistent,
        bool $force,
        bool $dryRun,
    ): TransferReport {
        $tables = TransferOptions::tables($datasets, $includeUsers);
        $tokens = TransferOptions::tokens($websiteTokens);
        $name = TransferOptions::packageName($packageName);
        $this->assertSchema($this->application, $tables);
        $root = $this->root();
        $final = $root.'/'.$name;
        if (is_link($final)) {
            throw new DataTransferException('The package name is not a real directory, so it was not used.');
        }
        if (is_dir($final) && !$force) {
            throw new DataTransferException('That package already exists. Choose another name, or pass --force to replace its transfer files.');
        }

        $hold = $this->beginRead($this->application, $consistent);
        $temp = null;
        $package = null;
        try {
            $counts = [];
            $enhanced = false;
            if (!$dryRun) {
                $temp = $root.'/'.$name.'.tmp-'.bin2hex(random_bytes(4));
                if (!mkdir($temp, 0700) && !is_dir($temp)) {
                    throw new DataTransferException('The package directory could not be created.');
                }
                $this->assertInsideRoot($temp);
                $package = new TransferPackage($temp);
            }
            foreach ($tables as $table) {
                $counts[$table] = $this->emptyCounts();
                foreach ($this->readEncoded($this->application, $table, $tokens) as $row) {
                    ++$counts[$table]['read'];
                    if ($table === 'events' && ($row['privacy_mode'] ?? null) === 'enhanced') {
                        $enhanced = true;
                    }
                    if ($package !== null) {
                        $package->writeRow($table, $row);
                        ++$counts[$table]['written'];
                    }
                }
            }
            if ($package !== null && $temp !== null) {
                $package->finish($this->manifest($counts, $enhanced, in_array('users', $tables, true)));
                $package = null;
                $this->publishPackage($temp, $final, $force);
                $temp = null;
            }

            return $this->report('export', $dryRun, $counts, $this->notes($enhanced, in_array('users', $tables, true), 0), $dryRun ? null : 'var/data-transfer/'.$name, null);
        } catch (DataTransferException $e) {
            $package?->discard();
            if ($temp !== null) {
                TransferPackage::discardDirectory($temp);
            }
            throw $e;
        } catch (\Throwable $e) {
            $package?->discard();
            if ($temp !== null) {
                TransferPackage::discardDirectory($temp);
            }
            throw new DataTransferException('The export could not be completed. No complete package was kept.', previous: $e);
        } finally {
            $this->endRead($this->application, $hold);
        }
    }

    /**
     * @param list<string>|null $datasets
     * @param list<string> $websiteTokens
     * @param array<string, string> $tokenMap
     */
    public function import(
        string $packageName,
        ?array $datasets,
        array $websiteTokens,
        array $tokenMap,
        bool $includeUsers,
        string $onConflict,
        bool $newIds,
        bool $dryRun,
    ): TransferReport {
        $directory = $this->packageDirectory(TransferOptions::packageName($packageName));
        $manifest = TransferPackage::readManifest($directory);

        return $this->writeIncoming(
            $this->application,
            'import',
            $datasets,
            $websiteTokens,
            $tokenMap,
            $includeUsers,
            $onConflict,
            $newIds,
            $dryRun,
            function (string $table) use ($directory, $manifest): \Generator {
                $info = $manifest['tables'][$table] ?? null;
                if (!is_array($info)) {
                    throw new DataTransferException(sprintf('This package has no %s table.', $table));
                }
                yield from TransferPackage::readRows($directory, $table, (int) $info['rows']);
            },
            is_array($manifest['tables']['users'] ?? null) && !$includeUsers,
        );
    }

    /**
     * @param list<string>|null $datasets
     * @param list<string> $websiteTokens
     * @param array<string, string> $tokenMap
     */
    public function copy(
        string $targetUrl,
        ?array $datasets,
        array $websiteTokens,
        array $tokenMap,
        bool $includeUsers,
        string $onConflict,
        bool $newIds,
        bool $consistent,
        bool $dryRun,
    ): TransferReport {
        $tokens = TransferOptions::tokens($websiteTokens);
        $target = $this->openTarget($targetUrl);
        $hold = false;
        try {
            $this->assertDifferentDatabase($target);
            $hold = $this->beginRead($this->application, $consistent);

            return $this->writeIncoming(
                $target,
                'copy',
                $datasets,
                $tokens,
                $tokenMap,
                $includeUsers,
                $onConflict,
                $newIds,
                $dryRun,
                function (string $table) use ($tokens): \Generator {
                    $line = 0;
                    foreach ($this->readEncoded($this->application, $table, $tokens) as $row) {
                        yield ++$line => $row;
                    }
                },
                false,
            );
        } finally {
            $this->endRead($this->application, $hold);
            $target->close();
        }
    }

    /** @return array<string, int|null> */
    public function counts(): array
    {
        $counts = [];
        foreach (TransferCatalog::tables() as $table) {
            try {
                $counts[$table] = (int) $this->application->fetchOne('SELECT COUNT(*) FROM '.$this->application->getDatabasePlatform()->quoteSingleIdentifier($table));
            } catch (\Throwable) {
                $counts[$table] = null;
            }
        }

        return $counts;
    }

    /**
     * @return list<array{name: string, readable: bool, created_at: ?string, rows: int, contains_users: bool, contains_enhanced_events: bool}>
     */
    public function packages(): array
    {
        $root = $this->projectDir.'/var/data-transfer';
        if (!is_dir($root) || is_link($root)) {
            return [];
        }
        $names = scandir($root);
        if ($names === false) {
            return [];
        }
        $packages = [];
        foreach ($names as $name) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $name) !== 1) {
                continue;
            }
            $path = $root.'/'.$name;
            if (is_link($path) || !is_dir($path)) {
                continue;
            }
            try {
                $manifest = TransferPackage::readManifest($path);
                $rows = 0;
                foreach ($manifest['tables'] as $info) {
                    if (is_array($info)) {
                        $rows += (int) ($info['rows'] ?? 0);
                    }
                }
                $packages[] = [
                    'name' => $name,
                    'readable' => true,
                    'created_at' => is_string($manifest['created_at'] ?? null) ? $manifest['created_at'] : null,
                    'rows' => $rows,
                    'contains_users' => (bool) ($manifest['contains_users'] ?? false),
                    'contains_enhanced_events' => (bool) ($manifest['contains_enhanced_events'] ?? false),
                ];
            } catch (DataTransferException) {
                $packages[] = [
                    'name' => $name,
                    'readable' => false,
                    'created_at' => null,
                    'rows' => 0,
                    'contains_users' => false,
                    'contains_enhanced_events' => false,
                ];
            }
        }

        return $packages;
    }

    public function targetConfigured(): bool
    {
        foreach ([getenv(self::TARGET_URL_ENV), $_ENV[self::TARGET_URL_ENV] ?? null, $_SERVER[self::TARGET_URL_ENV] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string>|null $datasets
     * @param list<string> $websiteTokens
     * @param array<string, string> $tokenMap
     * @param callable(string): \Generator<int, array<string, mixed>> $rows
     */
    private function writeIncoming(
        Connection $target,
        string $operation,
        ?array $datasets,
        array $websiteTokens,
        array $tokenMap,
        bool $includeUsers,
        string $onConflict,
        bool $newIds,
        bool $dryRun,
        callable $rows,
        bool $usersLeftOut,
    ): TransferReport {
        $tables = TransferOptions::tables($datasets, $includeUsers);
        $tokens = TransferOptions::tokens($websiteTokens);
        $map = $tokenMap;
        $onConflict = TransferOptions::onConflict($onConflict);
        $this->assertSchema($target, $tables);
        $idMap = $newIds && !$dryRun ? IdMap::open($this->root().'/id-maps') : null;
        $counts = [];
        $committed = [];
        try {
            foreach ($tables as $table) {
                $counts[$table] = $this->writeTable($target, $table, $rows($table), $tokens, $map, $onConflict, $newIds, $dryRun, $idMap);
                if (!$dryRun) {
                    $committed[] = $table;
                }
            }
        } catch (DataTransferException $e) {
            $idMap?->close();
            if ($committed !== []) {
                throw new DataTransferException($e->getMessage().' Tables already copied were kept. Run again with skip to continue.', previous: $e);
            }
            throw $e;
        } catch (\Throwable $e) {
            $idMap?->close();
            $message = 'The transfer could not be written, so the unfinished table was left unchanged.';
            if ($committed !== []) {
                $message .= ' Tables already copied were kept. Run again with skip to continue.';
            }
            throw new DataTransferException($message, previous: $e);
        }
        $idMap?->close();
        $filtered = 0;
        foreach ($counts as $count) {
            $filtered += $count['filtered'];
        }
        $notes = [];
        if ($usersLeftOut) {
            $notes[] = 'The package also has a users table, which was not imported.';
        }
        if ($map !== []) {
            $notes[] = 'Website tokens were rewritten where a mapping was given.';
        }
        if ($filtered > 0) {
            $notes[] = 'Some rows were outside the website filter and were left out.';
        }
        if ($newIds) {
            $notes[] = 'New ids were assigned for events and users. Archive cell keys were kept.';
        }

        return $this->report($operation, $dryRun, $counts, $notes, null, $idMap === null ? null : $this->relative($idMap->path()));
    }

    /**
     * @param list<string> $tokens
     * @param array<string, string> $tokenMap
     * @param iterable<int, array<string, mixed>> $rows
     *
     * @return array{read: int, written: int, skipped: int, replaced: int, filtered: int}
     */
    private function writeTable(
        Connection $target,
        string $table,
        iterable $rows,
        array $tokens,
        array $tokenMap,
        string $onConflict,
        bool $newIds,
        bool $dryRun,
        ?IdMap $idMap,
    ): array {
        $counts = $this->emptyCounts();
        $tokenSet = array_fill_keys($tokens, true);
        $explicitIds = !$newIds && TransferCatalog::isIdentity($table);
        $target->beginTransaction();
        try {
            if ($explicitIds) {
                $this->setIdentityInsert($target, $table, true);
            }
            $batch = [];
            $bytes = 0;
            $seenDimensions = [];
            foreach ($rows as $line => $raw) {
                ++$counts['read'];
                $decoded = RowCodec::decode($table, $raw, $line);
                $sourceToken = is_string($decoded['website_token'] ?? null) ? $decoded['website_token'] : '';
                $decoded = RowCodec::applyTokenMap($table, $decoded, $tokenMap);
                if (!$this->matchesWebsite($table, $decoded, $sourceToken, $tokenSet)) {
                    ++$counts['filtered'];
                    continue;
                }
                $this->assertDimensions($table, $decoded, $tokenMap, $seenDimensions);
                $batch[] = $decoded;
                $bytes += strlen((string) json_encode($decoded));
                if (count($batch) >= self::BATCH_ROWS || $bytes >= self::BATCH_BYTES) {
                    $this->flushBatch($target, $table, $batch, $onConflict, $newIds, $dryRun, $idMap, $counts);
                    $batch = [];
                    $bytes = 0;
                }
            }
            $this->flushBatch($target, $table, $batch, $onConflict, $newIds, $dryRun, $idMap, $counts);
            if ($explicitIds) {
                $this->setIdentityInsert($target, $table, false);
            }
            if ($dryRun) {
                $target->rollBack();
            } else {
                $target->commit();
                if ($explicitIds && $counts['written'] + $counts['replaced'] > 0) {
                    $this->reseatIdentity($target, $table);
                }
            }
        } catch (\Throwable $e) {
            $this->setIdentityInsert($target, $table, false);
            if ($target->isTransactionActive()) {
                $target->rollBack();
            }
            throw $e;
        }

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $batch
     * @param array{read: int, written: int, skipped: int, replaced: int, filtered: int} $counts
     */
    private function flushBatch(
        Connection $target,
        string $table,
        array $batch,
        string $onConflict,
        bool $newIds,
        bool $dryRun,
        ?IdMap $idMap,
        array &$counts,
    ): void {
        if ($batch === []) {
            return;
        }
        if ($table === 'users') {
            $this->flushUsers($target, $batch, $onConflict, $newIds, $dryRun, $idMap, $counts);

            return;
        }
        $primary = TransferCatalog::primary($table);
        $existing = $newIds ? [] : $this->existingKeys($target, $table, array_column($batch, $primary));
        $inserts = [];
        foreach ($batch as $row) {
            $key = $this->keyString($row[$primary]);
            if (isset($existing[$key])) {
                $this->conflict($target, $table, $row, $onConflict, $dryRun, $counts);
                continue;
            }
            if ($newIds && TransferCatalog::isIdentity($table)) {
                if (!$dryRun) {
                    $newId = $this->insertOne($target, $table, $this->withoutId($row), true);
                    $idMap?->remember($table, $key, $newId);
                }
                ++$counts['written'];
                continue;
            }
            $inserts[] = $row;
        }
        if ($inserts !== [] && !$dryRun) {
            $this->insertMany($target, $table, $inserts);
        }
        $counts['written'] += count($inserts);
    }

    /**
     * @param list<array<string, mixed>> $batch
     * @param array{read: int, written: int, skipped: int, replaced: int, filtered: int} $counts
     */
    private function flushUsers(
        Connection $target,
        array $batch,
        string $onConflict,
        bool $newIds,
        bool $dryRun,
        ?IdMap $idMap,
        array &$counts,
    ): void {
        $byName = $this->existingUsers($target, array_column($batch, 'username'));
        $byId = $newIds ? [] : $this->existingKeys($target, 'users', array_column($batch, 'id'));
        $inserts = [];
        foreach ($batch as $row) {
            $username = (string) $row['username'];
            $id = $this->keyString($row['id']);
            $nameId = $byName[$username] ?? null;
            $idExists = isset($byId[$id]);
            if ($nameId !== null && $idExists && $nameId !== $id) {
                throw new DataTransferException('A user account conflicts with an existing account, so users were not imported.');
            }
            if ($nameId !== null || $idExists) {
                if ($onConflict === 'fail') {
                    throw new DataTransferException('A user account already exists. Nothing further was written for users.');
                }
                if ($onConflict === 'skip') {
                    ++$counts['skipped'];
                    if (!$dryRun && $nameId !== null) {
                        $idMap?->remember('users', $id, $nameId);
                    }
                    continue;
                }
                if (!$dryRun) {
                    if ($nameId !== null) {
                        $this->updateWhere($target, 'users', $this->withoutId($row), 'username', $username);
                        $idMap?->remember('users', $id, $nameId);
                    } else {
                        $this->updateRow($target, 'users', $row);
                    }
                }
                ++$counts['replaced'];
                continue;
            }
            if ($newIds) {
                if (!$dryRun) {
                    $newId = $this->insertOne($target, 'users', $this->withoutId($row), true);
                    $idMap?->remember('users', $id, $newId);
                }
                ++$counts['written'];
                continue;
            }
            $inserts[] = $row;
            $byId[$id] = true;
            $byName[$username] = $id;
        }
        if ($inserts !== [] && !$dryRun) {
            $this->insertMany($target, 'users', $inserts);
        }
        $counts['written'] += count($inserts);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{read: int, written: int, skipped: int, replaced: int, filtered: int} $counts
     */
    private function conflict(Connection $target, string $table, array $row, string $onConflict, bool $dryRun, array &$counts): void
    {
        if ($onConflict === 'fail') {
            throw new DataTransferException(sprintf('A %s row already exists. Nothing further was written for that table.', $table));
        }
        if ($onConflict === 'skip') {
            ++$counts['skipped'];

            return;
        }
        if (!$dryRun) {
            $this->updateRow($target, $table, $row);
        }
        ++$counts['replaced'];
    }

    /**
     * @param list<string> $tokens
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function readEncoded(Connection $source, string $table, array $tokens): \Generator
    {
        $tokenSet = array_fill_keys($tokens, true);
        $line = 0;
        foreach ($this->readRaw($source, $table, $tokens) as $row) {
            $encoded = RowCodec::encode($table, $row);
            if ($tokenSet !== [] && TransferCatalog::hasWebsite($table)) {
                $token = $encoded['website_token'] ?? null;
                if (!is_string($token) || !isset($tokenSet[$token])) {
                    continue;
                }
            }
            yield ++$line => $encoded;
        }
    }

    /**
     * @param list<string> $tokens
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function readRaw(Connection $source, string $table, array $tokens): \Generator
    {
        $platform = $source->getDatabasePlatform();
        $quoted = [];
        foreach (array_keys(TransferCatalog::columns($table)) as $column) {
            $quoted[] = $platform->quoteSingleIdentifier($column);
        }
        $sql = 'SELECT '.implode(', ', $quoted).' FROM '.$platform->quoteSingleIdentifier($table);
        $params = [];
        if ($tokens !== [] && TransferCatalog::hasWebsite($table)) {
            $sql .= ' WHERE '.$platform->quoteSingleIdentifier('website_token').' IN ('.implode(', ', array_fill(0, count($tokens), '?')).')';
            $params = $tokens;
        }
        $sql .= ' ORDER BY '.$platform->quoteSingleIdentifier(TransferCatalog::primary($table));
        foreach ($source->executeQuery($sql, $params)->iterateAssociative() as $row) {
            if (!is_array($row)) {
                throw new DataTransferException(sprintf('The %s table returned a row that could not be read.', $table));
            }
            yield $row;
        }
    }

    /** @param list<mixed> $keys
     *  @return array<string, true>
     */
    private function existingKeys(Connection $connection, string $table, array $keys): array
    {
        $keys = array_values(array_filter($keys, static fn (mixed $key): bool => $key !== null && $key !== ''));
        if ($keys === []) {
            return [];
        }
        $platform = $connection->getDatabasePlatform();
        $primary = TransferCatalog::primary($table);
        $kind = TransferCatalog::columns($table)[$primary]['kind'];
        $values = [];
        $types = [];
        foreach ($keys as $key) {
            $values[] = $this->bindValue($kind, $key);
            $types[] = $this->parameterType($kind, $key);
        }
        $sql = 'SELECT '.$platform->quoteSingleIdentifier($primary).' FROM '.$platform->quoteSingleIdentifier($table)
            .' WHERE '.$platform->quoteSingleIdentifier($primary).' IN ('.implode(', ', array_fill(0, count($values), '?')).')';
        $found = [];
        foreach ($connection->executeQuery($sql, $values, $types)->iterateColumn() as $value) {
            $found[$this->keyString($value)] = true;
        }

        return $found;
    }

    /**
     * @param list<mixed> $usernames
     *
     * @return array<string, string>
     */
    private function existingUsers(Connection $connection, array $usernames): array
    {
        $usernames = array_values(array_filter($usernames, static fn (mixed $name): bool => is_string($name) && $name !== ''));
        if ($usernames === []) {
            return [];
        }
        $platform = $connection->getDatabasePlatform();
        $sql = 'SELECT '.$platform->quoteSingleIdentifier('id').', '.$platform->quoteSingleIdentifier('username')
            .' FROM '.$platform->quoteSingleIdentifier('users')
            .' WHERE '.$platform->quoteSingleIdentifier('username').' IN ('.implode(', ', array_fill(0, count($usernames), '?')).')';
        $found = [];
        foreach ($connection->executeQuery($sql, $usernames)->iterateAssociative() as $row) {
            if (is_array($row) && is_string($row['username'] ?? null)) {
                $found[$row['username']] = $this->keyString($row['id'] ?? '');
            }
        }

        return $found;
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertMany(Connection $connection, string $table, array $rows): void
    {
        $platform = $connection->getDatabasePlatform();
        $columns = array_keys($rows[0]);
        $quoted = array_map(static fn (string $column): string => $platform->quoteSingleIdentifier($column), $columns);
        $placeholders = [];
        $values = [];
        $types = [];
        $kinds = TransferCatalog::columns($table);
        foreach ($rows as $row) {
            $rowPlaceholders = [];
            foreach ($columns as $column) {
                $rowPlaceholders[] = '?';
                $values[] = $this->bindValue((string) $kinds[$column]['kind'], $row[$column]);
                $types[] = $this->parameterType((string) $kinds[$column]['kind'], $row[$column]);
            }
            $placeholders[] = '('.implode(', ', $rowPlaceholders).')';
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $platform->quoteSingleIdentifier($table),
            implode(', ', $quoted),
            implode(', ', $placeholders),
        );
        $connection->executeStatement($sql, $values, $types);
    }

    /** @param array<string, mixed> $row */
    private function insertOne(Connection $connection, string $table, array $row, bool $returnId): string
    {
        $platform = $connection->getDatabasePlatform();
        $columns = array_keys($row);
        $quoted = array_map(static fn (string $column): string => $platform->quoteSingleIdentifier($column), $columns);
        $values = [];
        $types = [];
        $kinds = TransferCatalog::columns($table);
        foreach ($columns as $column) {
            $values[] = $this->bindValue((string) $kinds[$column]['kind'], $row[$column]);
            $types[] = $this->parameterType((string) $kinds[$column]['kind'], $row[$column]);
        }
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $platform->quoteSingleIdentifier($table),
            implode(', ', $quoted),
            implode(', ', array_fill(0, count($columns), '?')),
        );
        if ($returnId && $platform instanceof PostgreSQLPlatform) {
            $id = $connection->executeQuery($sql.' RETURNING '.$platform->quoteSingleIdentifier('id'), $values, $types)->fetchOne();

            return $this->keyString($id);
        }
        $connection->executeStatement($sql, $values, $types);
        if (!$returnId) {
            return '';
        }
        if ($platform instanceof SQLServerPlatform) {
            return $this->keyString($connection->fetchOne('SELECT CONVERT(VARCHAR(20), SCOPE_IDENTITY())'));
        }

        return $this->keyString($connection->lastInsertId());
    }

    /** @param array<string, mixed> $row */
    private function updateRow(Connection $connection, string $table, array $row): void
    {
        $primary = TransferCatalog::primary($table);
        $this->updateWhere($connection, $table, $row, $primary, $row[$primary]);
    }

    /** @param array<string, mixed> $row */
    private function updateWhere(Connection $connection, string $table, array $row, string $whereColumn, mixed $whereValue): void
    {
        $platform = $connection->getDatabasePlatform();
        $kinds = TransferCatalog::columns($table);
        $assignments = [];
        $values = [];
        $types = [];
        foreach ($row as $column => $value) {
            if ($column === $whereColumn || $column === TransferCatalog::primary($table) && $whereColumn !== TransferCatalog::primary($table)) {
                continue;
            }
            if ($column === TransferCatalog::primary($table) && $whereColumn === TransferCatalog::primary($table)) {
                continue;
            }
            $assignments[] = $platform->quoteSingleIdentifier($column).' = ?';
            $values[] = $this->bindValue((string) $kinds[$column]['kind'], $value);
            $types[] = $this->parameterType((string) $kinds[$column]['kind'], $value);
        }
        $values[] = $this->bindValue((string) $kinds[$whereColumn]['kind'], $whereValue);
        $types[] = $this->parameterType((string) $kinds[$whereColumn]['kind'], $whereValue);
        $sql = 'UPDATE '.$platform->quoteSingleIdentifier($table).' SET '.implode(', ', $assignments)
            .' WHERE '.$platform->quoteSingleIdentifier($whereColumn).' = ?';
        $connection->executeStatement($sql, $values, $types);
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function withoutId(array $row): array
    {
        unset($row['id']);

        return $row;
    }

    /**
     * @param array<string, string> $tokenMap
     * @param array<string, true> $seen
     * @param array<string, mixed> $row
     */
    private function assertDimensions(string $table, array $row, array $tokenMap, array &$seen): void
    {
        $dimensions = TransferCatalog::dimensions($table);
        if ($tokenMap === [] || $dimensions === []) {
            return;
        }
        $parts = [];
        foreach ($dimensions as $column) {
            $value = $row[$column] ?? null;
            if ($value instanceof \DateTimeInterface) {
                $value = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            $parts[] = is_scalar($value) || $value === null ? (string) $value : '';
        }
        $hash = hash('sha256', implode("\0", $parts));
        if (isset($seen[$hash])) {
            throw new DataTransferException('Token mapping would combine archive cells that report as the same cell. Counts are not summed, so those archives were not imported.');
        }
        $seen[$hash] = true;
    }

    /** @param array<string, true> $tokenSet */
    private function matchesWebsite(string $table, array $row, string $sourceToken, array $tokenSet): bool
    {
        if ($tokenSet === [] || !TransferCatalog::hasWebsite($table)) {
            return true;
        }
        $current = $row['website_token'] ?? null;

        return (is_string($current) && isset($tokenSet[$current])) || isset($tokenSet[$sourceToken]);
    }

    /** @param list<string> $tables */
    private function assertSchema(Connection $connection, array $tables): void
    {
        try {
            $schema = $connection->createSchemaManager();
            foreach ($tables as $table) {
                if (!$schema->tablesExist([$table])) {
                    throw new DataTransferException(sprintf('The %s table is missing. Run doctrine:migrations:migrate before transferring.', $table));
                }
                $columns = array_change_key_case($schema->listTableColumns($table));
                foreach (array_keys(TransferCatalog::columns($table)) as $name) {
                    if (!isset($columns[strtolower($name)])) {
                        throw new DataTransferException(sprintf('The %s table is missing %s. Run doctrine:migrations:migrate before transferring.', $table, $name));
                    }
                }
            }
        } catch (DataTransferException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DataTransferException('The database schema could not be checked. Run doctrine:migrations:migrate before transferring.', previous: $e);
        }
    }

    private function reseatIdentity(Connection $connection, string $table): void
    {
        $platform = $connection->getDatabasePlatform();
        $quoted = $platform->quoteSingleIdentifier($table);
        $max = $connection->fetchOne('SELECT MAX('.$platform->quoteSingleIdentifier('id').') FROM '.$quoted);
        if ($max === null || $max === false || $max === '') {
            return;
        }
        $next = (int) $max;
        try {
            if ($platform instanceof PostgreSQLPlatform) {
                $connection->executeStatement("SELECT setval(pg_get_serial_sequence('".$table."', 'id'), ?, true)", [$next]);
            } elseif ($platform instanceof AbstractMySQLPlatform) {
                $connection->executeStatement('ALTER TABLE '.$quoted.' AUTO_INCREMENT = '.($next + 1));
            } elseif ($platform instanceof SQLServerPlatform) {
                $connection->executeStatement('DBCC CHECKIDENT ('.$quoted.', RESEED, '.$next.')');
            } elseif ($platform instanceof SQLitePlatform) {
                $connection->executeStatement('UPDATE sqlite_sequence SET seq = ? WHERE name = ?', [$next, $table]);
            }
        } catch (\Throwable $e) {
            throw new DataTransferException(sprintf('Rows were written, but the %s id sequence could not be moved. Set it above the highest id before collecting new rows.', $table), previous: $e);
        }
    }

    private function setIdentityInsert(Connection $connection, string $table, bool $on): void
    {
        if (!$connection->getDatabasePlatform() instanceof SQLServerPlatform) {
            return;
        }
        $connection->executeStatement('SET IDENTITY_INSERT '.$connection->getDatabasePlatform()->quoteSingleIdentifier($table).' '.($on ? 'ON' : 'OFF'));
    }

    private function openTarget(string $url): Connection
    {
        if (str_contains($url, "\0") || str_contains($url, "\n") || str_contains($url, "\r")) {
            throw new DataTransferException('The target database URL is not usable.');
        }
        try {
            $params = (new DsnParser(self::SCHEME_MAP))->parse(str_replace(ProjectDirEnvVarProcessor::TOKEN, $this->projectDir, trim($url)));
            $driver = $params['driver'] ?? null;
            if (!is_string($driver) || !in_array($driver, ['pdo_mysql', 'mysqli', 'pdo_pgsql', 'pgsql', 'pdo_sqlsrv', 'sqlsrv', 'pdo_sqlite', 'sqlite3'], true)) {
                throw new DataTransferException('The target database URL must use MySQL, MariaDB, PostgreSQL, SQL Server, or SQLite.');
            }
            if (in_array($driver, ['pdo_sqlite', 'sqlite3'], true)) {
                $path = $params['path'] ?? null;
                if (($params['memory'] ?? false) || !is_string($path) || $path === '' || $path === ':memory:') {
                    throw new DataTransferException('The target database URL must name a SQLite file, for example sqlite:///%kernel.project_dir%/var/data.db.');
                }
                // Three slashes leave a path relative to this installation.
                // The documented form starts with the project-dir token, which
                // becomes an absolute path.
                if (!str_starts_with($path, '/')) {
                    $params['path'] = $this->projectDir.'/'.$path;
                }
            }
            if (in_array($driver, ['pdo_mysql', 'mysqli'], true)) {
                $params['charset'] ??= 'utf8mb4';
            }
            $connection = DriverManager::getConnection($params);
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (DataTransferException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DataTransferException('The target database could not be opened. Check the URL, the PHP driver, and that the database exists.', previous: $e);
        }

        return $connection;
    }

    private function assertDifferentDatabase(Connection $target): void
    {
        $left = $this->identity($this->application);
        $right = $this->identity($target);
        if ($left === null || $right === null || $left === $right) {
            throw new DataTransferException('The target database could not be confirmed as a different database, so nothing was copied.');
        }
    }

    /** @return array{driver: string, name: string, host: string, port: string}|null */
    private function identity(Connection $connection): ?array
    {
        $params = $connection->getParams();
        $driver = $params['driver'] ?? null;
        if (!is_string($driver) || $driver === '') {
            return null;
        }
        if (in_array($driver, ['pdo_sqlite', 'sqlite3'], true)) {
            $path = $params['path'] ?? null;
            if (!is_string($path) || $path === '' || $path === ':memory:') {
                return null;
            }
            $real = realpath($path);

            return ['driver' => 'sqlite', 'name' => $real !== false ? $real : $path, 'host' => '', 'port' => ''];
        }
        $name = $params['dbname'] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }
        $host = strtolower((string) ($params['host'] ?? ''));
        if (in_array($host, ['', 'localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            $host = 'local';
        }

        return ['driver' => $driver, 'name' => $name, 'host' => $host, 'port' => (string) ($params['port'] ?? '')];
    }

    private function beginRead(Connection $connection, bool $consistent): bool
    {
        if (!$consistent) {
            return false;
        }
        if ($connection->isTransactionActive()) {
            throw new DataTransferException('A transfer cannot start a consistent read while another transaction is open.');
        }
        $connection->beginTransaction();

        return true;
    }

    private function endRead(Connection $connection, bool $hold): void
    {
        if ($hold && $connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    private function root(): string
    {
        $root = $this->projectDir.'/var/data-transfer';
        if (is_link($root)) {
            throw new DataTransferException('The data-transfer directory is not a real directory, so it was not used.');
        }
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new DataTransferException('The data-transfer directory could not be created.');
        }
        chmod($root, 0700);
        $real = realpath($root);
        if ($real === false) {
            throw new DataTransferException('The data-transfer directory could not be created.');
        }

        return $real;
    }

    private function packageDirectory(string $name): string
    {
        $path = $this->root().'/'.$name;
        if (is_link($path) || !is_dir($path)) {
            throw new DataTransferException('That package does not exist.');
        }
        $this->assertInsideRoot($path);

        return realpath($path) ?: $path;
    }

    private function assertInsideRoot(string $path): void
    {
        $real = realpath($path);
        $root = $this->root();
        if ($real === false || ($real !== $root && !str_starts_with($real, $root.DIRECTORY_SEPARATOR))) {
            throw new DataTransferException('The package path is outside the data-transfer directory, so it was not used.');
        }
    }

    private function publishPackage(string $temp, string $final, bool $force): void
    {
        if (is_link($final)) {
            throw new DataTransferException('The package name is not a real directory, so it was not used.');
        }
        $aside = null;
        if (is_dir($final)) {
            if (!$force) {
                throw new DataTransferException('That package already exists. Choose another name, or pass --force to replace its transfer files.');
            }
            $aside = $final.'.old-'.bin2hex(random_bytes(4));
            if (!rename($final, $aside)) {
                throw new DataTransferException('The existing package could not be replaced.');
            }
        }
        if (!rename($temp, $final)) {
            if ($aside !== null && !is_dir($final)) {
                rename($aside, $final);
            }
            throw new DataTransferException('The package could not be saved.');
        }
        if ($aside !== null) {
            TransferPackage::discardDirectory($aside);
        }
    }

    /**
     * @param array<string, array{read: int, written: int, skipped: int, replaced: int, filtered: int}> $counts
     *
     * @return array<string, mixed>
     */
    private function manifest(array $counts, bool $enhanced, bool $users): array
    {
        $tables = [];
        foreach ($counts as $table => $count) {
            $tables[$table] = ['rows' => $count['written']];
        }

        return [
            'format' => TransferCatalog::FORMAT,
            'product' => 'aggregate',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'source_engine' => $this->engineName($this->application),
            'contains_enhanced_events' => $enhanced,
            'contains_users' => $users,
            'tables' => $tables,
        ];
    }

    private function engineName(Connection $connection): string
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => 'pgsql',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof SQLServerPlatform => 'sqlsrv',
            $platform instanceof SQLitePlatform => 'sqlite',
            default => 'other',
        };
    }

    /**
     * @param array<string, array{read: int, written: int, skipped: int, replaced: int, filtered: int}> $counts
     * @param list<string> $notes
     */
    private function report(string $operation, bool $dryRun, array $counts, array $notes, ?string $packagePath, ?string $idMapPath): TransferReport
    {
        $written = 0;
        foreach ($counts as $count) {
            $written += $count['written'] + $count['replaced'];
        }

        return new TransferReport($operation, $dryRun, $counts, $written, $notes, $packagePath, $idMapPath);
    }

    /** @return list<string> */
    private function notes(bool $enhanced, bool $users, int $filtered): array
    {
        $notes = [];
        if ($enhanced) {
            $notes[] = 'The package contains enhanced events, including identifiers. Keep it private.';
        }
        if ($users) {
            $notes[] = 'The package contains password hashes. Keep it private.';
        }
        if ($filtered > 0) {
            $notes[] = 'Some rows were outside the website filter and were left out.';
        }

        return $notes;
    }

    /** @return array{read: int, written: int, skipped: int, replaced: int, filtered: int} */
    private function emptyCounts(): array
    {
        return ['read' => 0, 'written' => 0, 'skipped' => 0, 'replaced' => 0, 'filtered' => 0];
    }

    private function bindValue(string $kind, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            $utc = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));

            return $kind === 'date' ? $utc->format('Y-m-d') : $utc->format('Y-m-d H:i:s');
        }
        if ($kind === 'json' || $kind === 'roles') {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if ($kind === 'bigint') {
            return (string) $value;
        }

        return $value;
    }

    private function parameterType(string $kind, mixed $value): ParameterType
    {
        if ($value === null) {
            return ParameterType::NULL;
        }

        return $kind === 'integer' ? ParameterType::INTEGER : ParameterType::STRING;
    }

    private function keyString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function relative(string $path): string
    {
        $prefix = $this->projectDir.'/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }
}
