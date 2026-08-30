<?php

namespace App\Services;

use App\Models\ServerProfile;
use PDO;

final class SroCatalogService
{
    public function __construct(private readonly SqlServerConnectionFactory $connections) {}

    public function items(ServerProfile $profile, string $search = '', int $limit = 60, int $offset = 0): array
    {
        $database = $this->databaseContaining($profile, '_RefObjCommon');
        if (! $database) {
            return [];
        }

        $pdo = $this->connections->connect($profile, $database);
        $columns = $this->tableColumns($pdo, '_RefObjCommon');
        if (! isset($columns['ID'], $columns['CodeName128'])) {
            return [];
        }

        $optional = [
            'NameStrID128', 'AssocFileIcon128', 'TypeID1', 'TypeID2', 'TypeID3', 'TypeID4',
            'Degree', 'Rarity', 'Price', 'SellPrice', 'MaxStack', 'Service', 'Link',
        ];
        $select = ['[ID]', '[CodeName128]'];
        foreach ($optional as $column) {
            if (isset($columns[$column])) {
                $select[] = '['.$column.']';
            }
        }

        $where = [];
        $params = [];
        $orderParams = [];
        $orderBy = '[ID] DESC';
        if (isset($columns['TypeID1'])) {
            $where[] = '[TypeID1] = 3';
        }
        if (isset($columns['Service'])) {
            $where[] = '[Service] = 1';
        }
        if ($search !== '') {
            $where[] = '([CodeName128] LIKE ?'.(isset($columns['NameStrID128']) ? ' OR [NameStrID128] LIKE ?' : '').')';
            $params[] = '%'.$search.'%';
            if (isset($columns['NameStrID128'])) {
                $params[] = '%'.$search.'%';
            }
            $orderBy = 'CASE WHEN [CodeName128] LIKE ? THEN 0'.(isset($columns['NameStrID128']) ? ' WHEN [NameStrID128] LIKE ? THEN 1' : '').' ELSE 2 END, [ID] DESC';
            $orderParams[] = $search.'%';
            if (isset($columns['NameStrID128'])) {
                $orderParams[] = $search.'%';
            }
        }

        $limit = max(1, min($limit, 200));
        $sql = 'SELECT '.implode(', ', $select).' FROM [dbo].[_RefObjCommon]';
        if ($where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY '.$orderBy.' OFFSET '.max(0, $offset).' ROWS FETCH NEXT '.$limit.' ROWS ONLY';

        $statement = $pdo->prepare($sql);
        $statement->execute(array_merge($params, $orderParams));

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function monsters(ServerProfile $profile, string $search, int $limit = 24): array
    {
        $database = $this->databaseContaining($profile, '_RefObjCommon');
        if (! $database) {
            return [];
        }

        $pdo = $this->connections->connect($profile, $database);
        $columns = $this->tableColumns($pdo, '_RefObjCommon');
        if (! isset($columns['ID'], $columns['CodeName128'], $columns['TypeID1'])) {
            return [];
        }

        $select = ['[ID]', '[CodeName128]'];
        foreach (['NameStrID128', 'Rarity', 'Service'] as $column) {
            if (isset($columns[$column])) {
                $select[] = '['.$column.']';
            }
        }

        $where = ['[TypeID1] = 1'];
        $params = [];
        $orderParams = [];
        $orderBy = '[ID] DESC';
        if (isset($columns['Service'])) {
            $where[] = '[Service] = 1';
        }
        if ($search !== '') {
            $where[] = '([CodeName128] LIKE ?'.(isset($columns['NameStrID128']) ? ' OR [NameStrID128] LIKE ?' : '').')';
            $params[] = '%'.$search.'%';
            if (isset($columns['NameStrID128'])) {
                $params[] = '%'.$search.'%';
            }
            $orderBy = 'CASE WHEN [CodeName128] LIKE ? THEN 0'.(isset($columns['NameStrID128']) ? ' WHEN [NameStrID128] LIKE ? THEN 1' : '').' ELSE 2 END, '.(isset($columns['Rarity']) ? '[Rarity] DESC, ' : '').'[ID] DESC';
            $orderParams[] = $search.'%';
            if (isset($columns['NameStrID128'])) {
                $orderParams[] = $search.'%';
            }
        }

        $sql = 'SELECT '.implode(', ', $select).' FROM [dbo].[_RefObjCommon] WHERE '.implode(' AND ', $where)
            .' ORDER BY '.$orderBy.' OFFSET 0 ROWS FETCH NEXT '.max(1, min($limit, 60)).' ROWS ONLY';
        $statement = $pdo->prepare($sql);
        $statement->execute(array_merge($params, $orderParams));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(ServerProfile $profile, string $table, ?string $where = null): ?int
    {
        $database = $this->databaseContaining($profile, $table);
        if (! $database) {
            return null;
        }

        $pdo = $this->connections->connect($profile, $database);
        $sql = 'SELECT COUNT_BIG(*) FROM [dbo].'.$this->connections->quoteIdentifier($table);
        if ($where) {
            $sql .= ' WHERE '.$where;
        }

        return (int) $pdo->query($sql)->fetchColumn();
    }

    public function itemCount(ServerProfile $profile, string $search = ''): int
    {
        $database = $this->databaseContaining($profile, '_RefObjCommon');
        if (! $database) {
            return 0;
        }
        $pdo = $this->connections->connect($profile, $database);
        $columns = $this->tableColumns($pdo, '_RefObjCommon');
        $conditions = [];
        if (isset($columns['TypeID1'])) {
            $conditions[] = '[TypeID1] = 3';
        }
        if (isset($columns['Service'])) {
            $conditions[] = '[Service] = 1';
        }
        $sql = 'SELECT COUNT_BIG(*) FROM [dbo].[_RefObjCommon]'.($conditions ? ' WHERE '.implode(' AND ', $conditions) : '');
        $params = [];
        if ($search !== '') {
            $sql .= str_contains($sql, ' WHERE ') ? ' AND ' : ' WHERE ';
            $sql .= '([CodeName128] LIKE ?'.(isset($columns['NameStrID128']) ? ' OR [NameStrID128] LIKE ?' : '').')';
            $params[] = '%'.$search.'%';
            if (isset($columns['NameStrID128'])) {
                $params[] = '%'.$search.'%';
            }
        }
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    public function item(ServerProfile $profile, int $id): ?array
    {
        $database = $this->databaseContaining($profile, '_RefObjCommon');
        if (! $database) {
            return null;
        }

        $pdo = $this->connections->connect($profile, $database);
        $columns = $this->tableColumns($pdo, '_RefObjCommon');
        if (! isset($columns['ID'])) {
            return null;
        }

        $statement = $pdo->prepare('SELECT * FROM [dbo].[_RefObjCommon] WHERE [ID] = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        if (! $row) {
            return null;
        }

        $linked = null;
        if ((int) ($row['TypeID1'] ?? 0) === 3 && (int) ($row['Link'] ?? 0) > 0) {
            $linkedColumns = $this->tableColumns($pdo, '_RefObjItem');
            if ($linkedColumns) {
                $linkedStatement = $pdo->prepare('SELECT * FROM [dbo].[_RefObjItem] WHERE [ID] = ?');
                $linkedStatement->execute([(int) $row['Link']]);
                if ($linkedRow = $linkedStatement->fetch()) {
                    $linked = ['table' => '_RefObjItem', 'columns' => $linkedColumns, 'row' => $linkedRow];
                }
            }
        }

        return ['database' => $database, 'columns' => $columns, 'row' => $row, 'linked' => $linked];
    }

    public function updateItem(ServerProfile $profile, int $id, array $changes, array $detailChanges = []): array
    {
        $item = $this->item($profile, $id);
        if (! $item) {
            throw new \RuntimeException('Item was not found in the connected catalog.');
        }

        $pdo = $this->connections->connect($profile, $item['database']);
        $pdo->beginTransaction();
        try {
            [$sql, $params] = $this->buildUpdate('_RefObjCommon', $item['columns'], $changes, 'ID', $id);
            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            $rowCount = $statement->rowCount();
            $sqlParts = [$sql];

            if ($detailChanges && $item['linked']) {
                [$detailSql, $detailParams] = $this->buildUpdate(
                    $item['linked']['table'],
                    $item['linked']['columns'],
                    $detailChanges,
                    'ID',
                    (int) $item['linked']['row']['ID']
                );
                $detailStatement = $pdo->prepare($detailSql);
                $detailStatement->execute($detailParams);
                $rowCount += $detailStatement->rowCount();
                $sqlParts[] = $detailSql;
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return ['database' => $item['database'], 'sql' => implode(";\n", $sqlParts), 'row_count' => $rowCount];
    }

    public function cloneItem(ServerProfile $profile, int $id, array $changes): array
    {
        $item = $this->item($profile, $id);
        if (! $item) {
            throw new \RuntimeException('Source item was not found in the connected catalog.');
        }
        if (isset($item['columns']['CodeName128']) && trim((string) ($changes['CodeName128'] ?? '')) === '') {
            throw new \RuntimeException('A unique CodeName128 is required when cloning an item.');
        }
        $pdo = $this->connections->connect($profile, $item['database']);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $pdo->beginTransaction();
        try {
            $commonIdentity = (bool) ($item['columns']['ID']['is_identity'] ?? false);
            $requestedId = array_key_exists('ID', $changes) && $changes['ID'] !== '' ? (int) $changes['ID'] : null;
            if ($requestedId !== null && $requestedId < 1) {
                throw new \RuntimeException('The new item ID must be a positive integer.');
            }
            $duplicate = $pdo->prepare('SELECT COUNT_BIG(*) FROM [dbo].[_RefObjCommon] WITH (UPDLOCK, HOLDLOCK) WHERE [CodeName128] = ? OR ([ID] = ? AND ? IS NOT NULL)');
            $duplicate->execute([$changes['CodeName128'], $requestedId, $requestedId]);
            if ((int) $duplicate->fetchColumn() > 0) {
                throw new \RuntimeException('The requested CodeName128 or item ID already exists. Choose unique values.');
            }

            $linkedSql = null;
            $sourceLink = (int) ($item['row']['Link'] ?? 0);
            if ($sourceLink > 0) {
                $linked = $this->cloneIdentityRow($pdo, '_RefObjItem', $sourceLink);
                $changes['Link'] = $linked['id'];
                $linkedSql = $linked['sql'];
            }

            if (! $commonIdentity) {
                $changes['ID'] = $requestedId ?? (int) $pdo->query('SELECT ISNULL(MAX([ID]), 0) + 1 FROM [dbo].[_RefObjCommon] WITH (UPDLOCK, HOLDLOCK)')->fetchColumn();
            }

            [$sql, $params] = $this->buildCloneInsert('_RefObjCommon', $item['columns'], $item['row'], $changes);
            $statement = $pdo->prepare($sql);
            $statement->execute($params);
            $newId = $commonIdentity
                ? (int) $pdo->query('SELECT CAST(SCOPE_IDENTITY() AS int)')->fetchColumn()
                : (int) $changes['ID'];
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return ['database' => $item['database'], 'sql' => trim(($linkedSql ? $linkedSql.";\n" : '').$sql), 'id' => $newId];
    }

    private function cloneIdentityRow(PDO $pdo, string $table, int $sourceId): array
    {
        $columns = $this->tableColumns($pdo, $table);
        if (! isset($columns['ID'])) {
            throw new \RuntimeException($table.' has no ID column.');
        }
        $statement = $pdo->prepare('SELECT * FROM [dbo].'.$this->connections->quoteIdentifier($table).' WHERE [ID] = ?');
        $statement->execute([$sourceId]);
        $row = $statement->fetch();
        if (! $row) {
            throw new \RuntimeException('Linked '.$table.' row #'.$sourceId.' was not found.');
        }

        $identity = (bool) ($columns['ID']['is_identity'] ?? false);
        $changes = [];
        if (! $identity) {
            $nextId = (int) $pdo->query('SELECT ISNULL(MAX([ID]), 0) + 1 FROM [dbo].'.$this->connections->quoteIdentifier($table).' WITH (UPDLOCK, HOLDLOCK)')->fetchColumn();
            if ($nextId < 1) {
                throw new \RuntimeException('Unable to allocate the linked '.$table.' identity.');
            }
            $changes['ID'] = $nextId;
        }

        [$sql, $params] = $this->buildCloneInsert($table, $columns, $row, $changes);
        $insert = $pdo->prepare($sql);
        $insert->execute($params);
        $newId = $identity
            ? (int) $pdo->query('SELECT CAST(SCOPE_IDENTITY() AS int)')->fetchColumn()
            : (int) $changes['ID'];
        if ($newId < 1) {
            throw new \RuntimeException('Unable to allocate the linked '.$table.' identity.');
        }

        return ['id' => $newId, 'sql' => $sql];
    }

    private function buildCloneInsert(string $table, array $columns, array $row, array $changes): array
    {
        $blocked = ['timestamp', 'rowversion', 'image', 'text', 'ntext', 'xml', 'geography', 'geometry', 'hierarchyid'];
        $insertColumns = [];
        $params = [];
        foreach ($columns as $column => $meta) {
            if ($meta['is_identity'] || ($meta['is_computed'] ?? false) || in_array(strtolower((string) $meta['type_name']), $blocked, true)) {
                continue;
            }
            if (! array_key_exists($column, $row) && ! array_key_exists($column, $changes)) {
                continue;
            }
            $insertColumns[] = $this->connections->quoteIdentifier($column);
            $value = array_key_exists($column, $changes) ? $changes[$column] : $row[$column];
            $params[] = $value === '' ? null : $value;
        }
        if (! $insertColumns) {
            throw new \RuntimeException('No cloneable fields were found for '.$table.'.');
        }
        $sql = 'INSERT INTO [dbo].'.$this->connections->quoteIdentifier($table).' ('.implode(', ', $insertColumns).') VALUES ('.implode(', ', array_fill(0, count($insertColumns), '?')).')';

        return [$sql, $params];
    }

    private function buildUpdate(string $table, array $columns, array $changes, string $keyColumn, int|string $keyValue): array
    {
        $blocked = ['timestamp', 'rowversion', 'image', 'text', 'ntext', 'xml', 'geography', 'geometry', 'hierarchyid'];
        $sets = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $meta = $columns[$column] ?? null;
            if (! $meta || $column === $keyColumn || $meta['is_identity'] || ($meta['is_computed'] ?? false) || in_array(strtolower((string) $meta['type_name']), $blocked, true)) {
                continue;
            }
            $sets[] = $this->connections->quoteIdentifier($column).' = ?';
            $params[] = $value === '' ? null : $value;
        }
        if (! $sets) {
            throw new \RuntimeException('No editable fields were supplied for '.$table.'.');
        }
        $params[] = $keyValue;
        $sql = 'UPDATE [dbo].'.$this->connections->quoteIdentifier($table).' SET '.implode(', ', $sets).' WHERE '.$this->connections->quoteIdentifier($keyColumn).' = ?';

        return [$sql, $params];
    }

    public function databaseContaining(ServerProfile $profile, string $table): ?string
    {
        foreach (array_unique(array_filter([$profile->shard_database, $profile->account_database, $profile->proxy_database, $profile->log_database])) as $database) {
            $pdo = $this->connections->connect($profile, $database);
            $statement = $pdo->prepare('SELECT COUNT(*) FROM sys.tables WHERE name = ?');
            $statement->execute([$table]);
            if ((int) $statement->fetchColumn() > 0) {
                return $database;
            }
        }

        return null;
    }

    public function tableColumns(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare('SELECT c.name, t.name AS type_name, c.max_length, c.is_nullable, c.is_identity, c.is_computed FROM sys.columns c JOIN sys.types t ON t.user_type_id = c.user_type_id WHERE c.object_id = OBJECT_ID(?)');
        $statement->execute(['dbo.'.$table]);

        $result = [];
        foreach ($statement->fetchAll() as $column) {
            $result[$column['name']] = $column;
        }

        return $result;
    }
}
