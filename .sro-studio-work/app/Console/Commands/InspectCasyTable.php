<?php

namespace App\Console\Commands;

use App\Models\ServerProfile;
use App\Services\SqlServerConnectionFactory;
use App\Services\SroCatalogService;
use Illuminate\Console\Command;
use RuntimeException;

class InspectCasyTable extends Command
{
    protected $signature = 'casy:inspect-table {database} {table} {--sample=0}';

    protected $description = 'Inspect an allowed discovered SQL Server table without exposing credentials';

    public function handle(SqlServerConnectionFactory $connections, SroCatalogService $catalog): int
    {
        $profile = ServerProfile::query()->where('is_active', true)->latest()->firstOrFail();
        $database = (string) $this->argument('database');
        $table = (string) $this->argument('table');
        $info = data_get($profile->schema_stats, 'databases.'.$database);
        if (! is_array($info) || ! in_array($table, $info['tables'] ?? [], true)) {
            throw new RuntimeException('The table is not part of the discovered active profile.');
        }

        $pdo = $connections->connect($profile, $database);
        $columns = $catalog->tableColumns($pdo, $table);
        $pk = $pdo->prepare(<<<'SQL'
SELECT c.name
FROM sys.indexes i
JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
WHERE i.is_primary_key = 1 AND i.object_id = OBJECT_ID(?)
ORDER BY ic.key_ordinal
SQL);
        $pk->execute(['dbo.'.$table]);
        $fk = $pdo->prepare(<<<'SQL'
SELECT COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS column_name,
       OBJECT_NAME(fkc.referenced_object_id) AS referenced_table,
       COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS referenced_column
FROM sys.foreign_key_columns fkc
WHERE fkc.parent_object_id = OBJECT_ID(?)
ORDER BY column_name
SQL);
        $fk->execute(['dbo.'.$table]);
        $count = (int) $pdo->query('SELECT COUNT_BIG(*) FROM [dbo].'.$connections->quoteIdentifier($table))->fetchColumn();

        $sample = [];
        $limit = max(0, min(5, (int) $this->option('sample')));
        if ($limit > 0) {
            $sample = $pdo->query('SELECT TOP '.$limit.' * FROM [dbo].'.$connections->quoteIdentifier($table))->fetchAll();
        }

        $this->line(json_encode([
            'database' => $database,
            'table' => $table,
            'row_count' => $count,
            'primary_key' => $pk->fetchAll(\PDO::FETCH_COLUMN),
            'foreign_keys' => $fk->fetchAll(),
            'columns' => array_values($columns),
            'sample' => $sample,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        return self::SUCCESS;
    }
}
