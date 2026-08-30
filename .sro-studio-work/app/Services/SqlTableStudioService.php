<?php

namespace App\Services;

use App\Models\ServerProfile;
use PDO;
use RuntimeException;

final class SqlTableStudioService
{
    public function __construct(
        private readonly SqlServerConnectionFactory $connections,
        private readonly SroCatalogService $catalog,
    ) {}

    public function browse(ServerProfile $profile, string $database, string $table, string $search = '', int|string|array|null $rowKey = null, int $page = 1, int $perPage = 60): array
    {
        $this->assertAllowed($profile, $database, $table);
        $pdo = $this->connections->connect($profile, $database);
        $columns = $this->catalog->tableColumns($pdo, $table);
        $primaryKeys = $this->primaryKeys($pdo, $table);
        $visibleColumns = array_slice(array_values(array_filter(
            array_keys($columns),
            fn (string $column) => ! in_array(strtolower((string) $columns[$column]['type_name']), ['image', 'binary', 'varbinary', 'timestamp', 'rowversion', 'xml'], true)
        )), 0, 14);
        // Some log/blob tables contain no displayable text columns. Keep the
        // browser useful by showing safe metadata columns instead of emitting
        // an invalid `SELECT  FROM ...` statement.
        if ($visibleColumns === []) {
            $visibleColumns = array_slice(array_keys($columns), 0, 8);
        }

        $page = max(1, $page);
        $perPage = min(200, max(10, $perPage));
        $where = '';
        $params = [];
        if ($search !== '') {
            $searchColumns = array_slice(array_keys(array_filter(
                $columns,
                fn (array $meta) => in_array(strtolower((string) $meta['type_name']), ['char', 'varchar', 'nchar', 'nvarchar'], true)
            )), 0, 8);
            if ($searchColumns) {
                $where = ' WHERE '.implode(' OR ', array_map(fn (string $column) => $this->connections->quoteIdentifier($column).' LIKE ?', $searchColumns));
                $params = array_fill(0, count($searchColumns), '%'.$search.'%');
            }
        }

        $select = implode(', ', array_map($this->connections->quoteIdentifier(...), $visibleColumns));
        $order = $primaryKeys ? ' ORDER BY '.$this->connections->quoteIdentifier($primaryKeys[0]).' DESC' : ' ORDER BY (SELECT NULL)';
        $countStatement = $pdo->prepare('SELECT COUNT_BIG(*) FROM [dbo].'.$this->connections->quoteIdentifier($table).$where);
        $countStatement->execute($params);
        $count = (int) $countStatement->fetchColumn();
        $statement = $pdo->prepare('SELECT '.$select.' FROM [dbo].'.$this->connections->quoteIdentifier($table).$where.$order.' OFFSET ? ROWS FETCH NEXT ? ROWS ONLY');
        $parameterIndex = 1;
        foreach ($params as $param) {
            $statement->bindValue($parameterIndex++, $param, PDO::PARAM_STR);
        }
        // SQL Server rejects string-bound OFFSET/FETCH values. Bind both as
        // integers explicitly so every discovered studio can load its cards.
        $statement->bindValue($parameterIndex++, ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->bindValue($parameterIndex, $perPage, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        $record = null;
        $normalizedKey = $this->normalizeKey($rowKey, $primaryKeys);
        if ($normalizedKey !== null) {
            [$keyWhere, $keyParams] = $this->keyWhere($primaryKeys, $normalizedKey);
            $recordStatement = $pdo->prepare('SELECT * FROM [dbo].'.$this->connections->quoteIdentifier($table).' WHERE '.$keyWhere);
            $recordStatement->execute($keyParams);
            $record = $recordStatement->fetch() ?: null;
        }

        $relations = array_values(array_filter(
            data_get($profile->schema_stats, 'relations', []),
            fn (array $relation) => $relation['database'] === $database && $relation['table_name'] === 'dbo.'.$table
        ));

        return compact('database', 'table', 'columns', 'primaryKeys', 'visibleColumns', 'rows', 'record', 'relations', 'count', 'page', 'perPage', 'normalizedKey') + ['write_allowed' => $this->writeAllowed($table)];
    }

    public function update(ServerProfile $profile, string $database, string $table, int|string|array $key, array $changes): array
    {
        if (! $this->writeAllowed($table)) {
            throw new RuntimeException('This table is read-only in Table Studio. Use its dedicated studio for safe changes.');
        }
        $data = $this->browse($profile, $database, $table, '', $key);
        if (! $data['primaryKeys'] || ! $data['record']) {
            throw new RuntimeException('A valid primary key is required for safe visual editing.');
        }

        $blockedTypes = ['timestamp', 'rowversion', 'image', 'binary', 'varbinary', 'text', 'ntext', 'xml', 'geography', 'geometry', 'hierarchyid'];
        $sets = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $meta = $data['columns'][$column] ?? null;
            if (! $meta || in_array($column, $data['primaryKeys'], true) || $meta['is_identity'] || ($meta['is_computed'] ?? false) || in_array(strtolower((string) $meta['type_name']), $blockedTypes, true)) {
                continue;
            }
            $sets[] = $this->connections->quoteIdentifier($column).' = ?';
            $params[] = $value === '' && $meta['is_nullable'] ? null : $value;
        }
        if (! $sets) {
            throw new RuntimeException('No safe editable fields were submitted.');
        }

        [$keyWhere, $keyParams] = $this->keyWhere($data['primaryKeys'], $data['normalizedKey']);
        $params = array_merge($params, $keyParams);
        $sql = 'UPDATE [dbo].'.$this->connections->quoteIdentifier($table).' SET '.implode(', ', $sets).' WHERE '.$keyWhere;
        $pdo = $this->connections->connect($profile, $database);
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return ['sql' => $sql, 'row_count' => $statement->rowCount(), 'key_column' => implode(', ', $data['primaryKeys'])];
    }

    public function insert(ServerProfile $profile, string $database, string $table, array $changes): array
    {
        if (! $this->writeAllowed($table)) {
            throw new RuntimeException('This table is read-only in Table Studio. Use its dedicated studio for safe changes.');
        }
        $data = $this->browse($profile, $database, $table);
        $blockedTypes = ['timestamp', 'rowversion', 'image', 'binary', 'varbinary', 'text', 'ntext', 'xml', 'geography', 'geometry', 'hierarchyid'];
        $insertColumns = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $meta = $data['columns'][$column] ?? null;
            if (! $meta || $meta['is_identity'] || ($meta['is_computed'] ?? false) || in_array(strtolower((string) $meta['type_name']), $blockedTypes, true)) {
                continue;
            }
            $insertColumns[] = $this->connections->quoteIdentifier($column);
            $params[] = $value === '' && $meta['is_nullable'] ? null : $value;
        }
        if (! $insertColumns) {
            throw new RuntimeException('No safe insert fields were supplied.');
        }
        $sql = 'INSERT INTO [dbo].'.$this->connections->quoteIdentifier($table).' ('.implode(', ', $insertColumns).') VALUES ('.implode(', ', array_fill(0, count($insertColumns), '?')).')';
        $pdo = $this->connections->connect($profile, $database);
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            $key = null;
            if (count($data['primaryKeys']) === 1) {
                $keyColumn = $data['primaryKeys'][0];
                $identity = (bool) ($data['columns'][$keyColumn]['is_identity'] ?? false);
                if ($identity) {
                    $key = $pdo->query('SELECT CAST(SCOPE_IDENTITY() AS nvarchar(128))')->fetchColumn();
                } elseif (array_key_exists($keyColumn, $changes)) {
                    $key = $changes[$keyColumn];
                }
            }
            $pdo->commit();

            return ['sql' => $sql, 'row_count' => $statement->rowCount(), 'key' => $key];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function primaryKeys(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare(<<<'SQL'
SELECT c.name
FROM sys.indexes i
JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
WHERE i.is_primary_key = 1 AND i.object_id = OBJECT_ID(?)
ORDER BY ic.key_ordinal
SQL);
        $statement->execute(['dbo.'.$table]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function assertAllowed(ServerProfile $profile, string $database, string $table): void
    {
        if (! preg_match('/^[A-Za-z0-9_$-]+$/', $database) || ! preg_match('/^[A-Za-z0-9_$-]+$/', $table)) {
            throw new RuntimeException('Unsafe database identifier.');
        }
        $info = data_get($profile->schema_stats, 'databases.'.$database);
        if (! is_array($info) || ! ($info['available'] ?? false) || ! in_array($table, $info['tables'] ?? [], true)) {
            throw new RuntimeException('This table is not part of the discovered active profile.');
        }
    }

    private function writeAllowed(string $table): bool
    {
        return in_array($table, (array) config('casy.table_studio.write_allowlist', []), true);
    }

    private function normalizeKey(int|string|array|null $key, array $primaryKeys): ?array
    {
        if ($key === null || $key === '' || ! $primaryKeys) {
            return null;
        }
        if (is_string($key) && str_starts_with(trim($key), '{')) {
            $decoded = json_decode($key, true);
            if (is_array($decoded)) {
                $key = $decoded;
            }
        }
        if (count($primaryKeys) === 1 && ! is_array($key)) {
            return [$primaryKeys[0] => $key];
        }
        if (! is_array($key)) {
            return null;
        }
        $normalized = [];
        foreach ($primaryKeys as $column) {
            if (! array_key_exists($column, $key) || is_array($key[$column])) {
                return null;
            }
            $normalized[$column] = $key[$column];
        }
        return $normalized;
    }

    private function keyWhere(array $primaryKeys, array $key): array
    {
        $parts = [];
        $params = [];
        foreach ($primaryKeys as $column) {
            $parts[] = $this->connections->quoteIdentifier($column).' = ?';
            $params[] = $key[$column];
        }
        return [implode(' AND ', $parts), $params];
    }
}
