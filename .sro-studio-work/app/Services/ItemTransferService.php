<?php

namespace App\Services;

use App\Models\ServerProfile;
use PDO;
use RuntimeException;
use Throwable;

final class ItemTransferService
{
    public function __construct(private readonly SqlServerConnectionFactory $connections) {}

    public function databases(ServerProfile $profile): array
    {
        $pdo = $this->connections->connect($profile, 'master');
        $names = $pdo->query("SELECT [name] FROM sys.databases WHERE [database_id] > 4 AND [state_desc] = 'ONLINE' AND HAS_DBACCESS([name]) = 1 ORDER BY [name]")->fetchAll(PDO::FETCH_COLUMN);
        $databases = [];

        foreach ($names as $name) {
            try {
                $database = $this->connections->connect($profile, $name);
                $statement = $database->query("SELECT CASE WHEN OBJECT_ID(N'dbo._RefObjCommon', N'U') IS NOT NULL THEN 1 ELSE 0 END AS has_common, CASE WHEN OBJECT_ID(N'dbo._RefObjItem', N'U') IS NOT NULL THEN 1 ELSE 0 END AS has_item");
                $tables = $statement->fetch();
                if ((int) $tables['has_common'] === 1 && (int) $tables['has_item'] === 1) {
                    $databases[] = [
                        'name' => $name,
                        'common_count' => (int) $database->query('SELECT COUNT_BIG(*) FROM [dbo].[_RefObjCommon]')->fetchColumn(),
                        'item_count' => (int) $database->query('SELECT COUNT_BIG(*) FROM [dbo].[_RefObjItem]')->fetchColumn(),
                    ];
                }
            } catch (Throwable) {
                // A database can be visible while a table or database-level permission is unavailable.
            }
        }

        return $databases;
    }

    public function inspect(ServerProfile $profile, string $sourceDatabase, string $destinationDatabase, int $commonId): array
    {
        $this->assertTransfer($profile, $sourceDatabase, $destinationDatabase);
        $source = $this->connections->connect($profile, $sourceDatabase);
        $destination = $this->connections->connect($profile, $destinationDatabase);

        $statement = $source->prepare('SELECT * FROM [dbo].[_RefObjCommon] WHERE [ID] = ?');
        $statement->execute([$commonId]);
        $common = $statement->fetch();
        if (! $common) {
            throw new RuntimeException("Common item ID {$commonId} was not found in {$sourceDatabase}.");
        }
        if ($common['Link'] === null) {
            throw new RuntimeException("Common item ID {$commonId} has no linked _RefObjItem row.");
        }

        $statement = $source->prepare('SELECT * FROM [dbo].[_RefObjItem] WHERE [ID] = ?');
        $statement->execute([(int) $common['Link']]);
        $item = $statement->fetch();
        if (! $item) {
            throw new RuntimeException("Linked item ID {$common['Link']} was not found in {$sourceDatabase}.");
        }

        $statement = $destination->prepare('SELECT [ID], [CodeName128] FROM [dbo].[_RefObjCommon] WHERE [ID] = ? OR [CodeName128] = ?');
        $statement->execute([$commonId, $common['CodeName128']]);
        $commonConflict = $statement->fetch() ?: null;
        $statement = $destination->prepare('SELECT [ID] FROM [dbo].[_RefObjItem] WHERE [ID] = ?');
        $statement->execute([(int) $common['Link']]);
        $itemConflict = $statement->fetch() ?: null;

        $commonCompatibility = $this->compatibility($source, $destination, '_RefObjCommon');
        $itemCompatibility = $this->compatibility($source, $destination, '_RefObjItem');

        return [
            'common' => $common,
            'item' => $item,
            'common_conflict' => $commonConflict,
            'item_conflict' => $itemConflict,
            'common_compatibility' => $commonCompatibility,
            'item_compatibility' => $itemCompatibility,
            'can_copy' => ! $commonConflict && ! $itemConflict && ! $commonCompatibility['required_missing'] && ! $itemCompatibility['required_missing'],
            'sql' => $this->previewSql($sourceDatabase, $destinationDatabase, $commonId, (int) $common['Link'], $commonCompatibility['columns'], $itemCompatibility['columns']),
        ];
    }

    public function copy(ServerProfile $profile, string $sourceDatabase, string $destinationDatabase, int $commonId): array
    {
        $inspection = $this->inspect($profile, $sourceDatabase, $destinationDatabase, $commonId);
        if (! $inspection['can_copy']) {
            throw new RuntimeException('The item cannot be copied until the destination conflicts or required-column differences shown in the preview are resolved.');
        }

        $pdo = $this->connections->connect($profile, $destinationDatabase);
        $source = $this->connections->quoteIdentifier($sourceDatabase);
        $itemId = (int) $inspection['common']['Link'];
        $activeIdentityTable = null;
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $pdo->beginTransaction();

        try {
            $statement = $pdo->prepare('SELECT [ID] FROM [dbo].[_RefObjCommon] WITH (UPDLOCK, HOLDLOCK) WHERE [ID] = ? OR [CodeName128] = ?');
            $statement->execute([$commonId, $inspection['common']['CodeName128']]);
            if ($statement->fetch()) {
                throw new RuntimeException('The common ID or CodeName now exists in the destination. No rows were copied.');
            }
            $statement = $pdo->prepare('SELECT [ID] FROM [dbo].[_RefObjItem] WITH (UPDLOCK, HOLDLOCK) WHERE [ID] = ?');
            $statement->execute([$itemId]);
            if ($statement->fetch()) {
                throw new RuntimeException('The linked item ID now exists in the destination. No rows were copied.');
            }

            $activeIdentityTable = $this->copyRow($pdo, $source, '_RefObjItem', $itemId, $inspection['item_compatibility']);
            if ($activeIdentityTable) {
                $pdo->exec("SET IDENTITY_INSERT [dbo].[{$activeIdentityTable}] OFF");
                $activeIdentityTable = null;
            }
            $activeIdentityTable = $this->copyRow($pdo, $source, '_RefObjCommon', $commonId, $inspection['common_compatibility']);
            if ($activeIdentityTable) {
                $pdo->exec("SET IDENTITY_INSERT [dbo].[{$activeIdentityTable}] OFF");
                $activeIdentityTable = null;
            }

            $pdo->commit();

            return [
                'common_id' => $commonId,
                'item_id' => $itemId,
                'code_name' => $inspection['common']['CodeName128'],
                'source_database' => $sourceDatabase,
                'destination_database' => $destinationDatabase,
                'sql' => $inspection['sql'],
            ];
        } catch (Throwable $exception) {
            if ($activeIdentityTable) {
                try {
                    $pdo->exec("SET IDENTITY_INSERT [dbo].[{$activeIdentityTable}] OFF");
                } catch (Throwable) {
                    // Rollback remains authoritative if SQL Server already ended the statement.
                }
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function copyRow(PDO $pdo, string $sourceDatabase, string $table, int $id, array $compatibility): ?string
    {
        $quotedColumns = implode(', ', array_map(fn (string $column) => $this->connections->quoteIdentifier($column), $compatibility['columns']));
        $identityTable = $compatibility['has_identity'] ? $table : null;
        if ($identityTable) {
            $pdo->exec("SET IDENTITY_INSERT [dbo].[{$table}] ON");
        }
        $statement = $pdo->prepare("INSERT INTO [dbo].[{$table}] ({$quotedColumns}) SELECT {$quotedColumns} FROM {$sourceDatabase}.[dbo].[{$table}] WHERE [ID] = ?");
        $statement->execute([$id]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException("CASY expected one {$table} row, but SQL Server copied {$statement->rowCount()}.");
        }

        return $identityTable;
    }

    private function compatibility(PDO $source, PDO $destination, string $table): array
    {
        $sourceColumns = $this->columns($source, $table);
        $destinationColumns = $this->columns($destination, $table);
        $columns = [];
        foreach ($sourceColumns as $name => $meta) {
            if (isset($destinationColumns[$name]) && ! $meta['blocked'] && ! $destinationColumns[$name]['blocked']) {
                $columns[] = $name;
            }
        }
        $requiredMissing = [];
        foreach ($destinationColumns as $name => $meta) {
            if (! in_array($name, $columns, true) && ! $meta['blocked'] && ! $meta['is_nullable'] && ! $meta['is_identity'] && ! $meta['has_default']) {
                $requiredMissing[] = $name;
            }
        }

        return [
            'columns' => $columns,
            'source_only' => array_values(array_diff(array_keys($sourceColumns), array_keys($destinationColumns))),
            'destination_only' => array_values(array_diff(array_keys($destinationColumns), array_keys($sourceColumns))),
            'required_missing' => $requiredMissing,
            'has_identity' => collect($columns)->contains(fn (string $name) => $destinationColumns[$name]['is_identity']),
        ];
    }

    private function columns(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare(<<<'SQL'
SELECT c.[name], c.[column_id], c.[is_nullable], c.[is_identity], c.[is_computed], ty.[name] AS type_name,
       CASE WHEN c.[default_object_id] <> 0 THEN 1 ELSE 0 END AS has_default
FROM sys.columns c
INNER JOIN sys.tables t ON t.[object_id] = c.[object_id]
INNER JOIN sys.schemas s ON s.[schema_id] = t.[schema_id]
INNER JOIN sys.types ty ON ty.[user_type_id] = c.[user_type_id]
WHERE s.[name] = N'dbo' AND t.[name] = ?
ORDER BY c.[column_id]
SQL);
        $statement->execute([$table]);
        $columns = [];
        foreach ($statement->fetchAll() as $column) {
            $type = strtolower((string) $column['type_name']);
            $columns[$column['name']] = [
                'is_nullable' => (bool) $column['is_nullable'],
                'is_identity' => (bool) $column['is_identity'],
                'has_default' => (bool) $column['has_default'],
                'blocked' => (bool) $column['is_computed'] || in_array($type, ['timestamp', 'rowversion'], true),
            ];
        }

        return $columns;
    }

    private function assertTransfer(ServerProfile $profile, string $sourceDatabase, string $destinationDatabase): void
    {
        if ($sourceDatabase === $destinationDatabase) {
            throw new RuntimeException('Choose two different databases. Use regular Item Clone for the same database.');
        }
        $available = array_column($this->databases($profile), 'name');
        if (! in_array($sourceDatabase, $available, true) || ! in_array($destinationDatabase, $available, true)) {
            throw new RuntimeException('Both databases must be accessible and contain _RefObjCommon plus _RefObjItem.');
        }
    }

    private function previewSql(string $sourceDatabase, string $destinationDatabase, int $commonId, int $itemId, array $commonColumns, array $itemColumns): string
    {
        $source = $this->connections->quoteIdentifier($sourceDatabase);
        $destination = $this->connections->quoteIdentifier($destinationDatabase);
        $common = implode(', ', array_map(fn (string $column) => $this->connections->quoteIdentifier($column), $commonColumns));
        $item = implode(', ', array_map(fn (string $column) => $this->connections->quoteIdentifier($column), $itemColumns));

        return "SET XACT_ABORT ON;\nBEGIN TRY\n    BEGIN TRANSACTION;\n\n    -- Linked item row is copied first, then its common definition.\n    INSERT INTO {$destination}.[dbo].[_RefObjItem] ({$item})\n    SELECT {$item} FROM {$source}.[dbo].[_RefObjItem] WHERE [ID] = {$itemId};\n\n    INSERT INTO {$destination}.[dbo].[_RefObjCommon] ({$common})\n    SELECT {$common} FROM {$source}.[dbo].[_RefObjCommon] WHERE [ID] = {$commonId};\n\n    COMMIT TRANSACTION;\nEND TRY\nBEGIN CATCH\n    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;\n    THROW;\nEND CATCH;";
    }
}
