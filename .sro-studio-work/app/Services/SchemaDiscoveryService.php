<?php

namespace App\Services;

use App\Models\ServerProfile;
use PDO;

final class SchemaDiscoveryService
{
    public function __construct(private readonly SqlServerConnectionFactory $connections) {}

    public function inspect(ServerProfile $profile): array
    {
        $master = $this->connections->connect($profile, 'master');
        $server = $master->query("SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(128)) AS version, CAST(SERVERPROPERTY('Edition') AS nvarchar(128)) AS edition")->fetch();
        $available = $master->query("SELECT name FROM sys.databases WHERE state_desc = 'ONLINE' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

        $selected = array_values(array_unique(array_filter([
            $profile->account_database,
            $profile->shard_database,
            $profile->log_database,
            $profile->proxy_database,
        ])));

        $databases = [];
        $allTables = [];
        foreach ($selected as $database) {
            if (! in_array($database, $available, true)) {
                $databases[$database] = ['available' => false];
                continue;
            }

            $pdo = $this->connections->connect($profile, $database);
            $stats = $pdo->query(<<<'SQL'
SELECT
    (SELECT COUNT(*) FROM sys.tables) AS table_count,
    (SELECT COUNT(*) FROM sys.columns) AS column_count,
    (SELECT COUNT(*) FROM sys.foreign_keys) AS foreign_key_count,
    (SELECT COUNT(*) FROM sys.procedures) AS procedure_count,
    (SELECT COUNT(*) FROM sys.views) AS view_count
SQL)->fetch();
            $tables = $pdo->query('SELECT name FROM sys.tables ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
            $relations = $pdo->query(<<<'SQL'
SELECT fk.name AS foreign_key_name,
       OBJECT_SCHEMA_NAME(fkc.parent_object_id) + '.' + OBJECT_NAME(fkc.parent_object_id) AS table_name,
       COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS column_name,
       OBJECT_SCHEMA_NAME(fkc.referenced_object_id) + '.' + OBJECT_NAME(fkc.referenced_object_id) AS referenced_table,
       COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS referenced_column
FROM sys.foreign_key_columns fkc
JOIN sys.foreign_keys fk ON fk.object_id = fkc.constraint_object_id
ORDER BY table_name, foreign_key_name
SQL)->fetchAll();
            $primaryKeys = $pdo->query(<<<'SQL'
SELECT t.name AS table_name, c.name AS column_name, c.is_identity
FROM sys.tables t
JOIN sys.indexes i ON i.object_id = t.object_id AND i.is_primary_key = 1
JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
ORDER BY t.name, ic.key_ordinal
SQL)->fetchAll();
            $databases[$database] = array_merge(['available' => true], $stats, ['tables' => $tables, 'relations' => $relations, 'primary_keys' => $primaryKeys]);
            foreach ($tables as $table) {
                $allTables[$table] = $database;
            }
        }

        $capabilityTables = [
            'items' => ['_RefObjCommon'],
            'npc_shops' => ['_RefShopGoods', '_RefShopTab'],
            'item_mall' => ['_RefPackageItem', '_RefPricePolicyOfItem'],
            'characters' => ['_Char', '_Items', '_Inventory'],
            'monsters' => ['Tab_RefTactics', 'Tab_RefHive', 'Tab_RefNest'],
            'drops' => ['_RefDropItemGroup'],
            'teleports' => ['_RefTeleport', '_RefTeleLink'],
            'quests' => ['_RefQuest'],
        ];

        $capabilities = [];
        foreach ($capabilityTables as $capability => $required) {
            $capabilities[$capability] = [
                'ready' => count(array_intersect($required, array_keys($allTables))) === count($required),
                'found' => array_values(array_intersect($required, array_keys($allTables))),
                'required' => $required,
            ];
        }

        $fingerprintSource = [];
        $totals = ['table_count' => 0, 'column_count' => 0, 'foreign_key_count' => 0, 'procedure_count' => 0, 'view_count' => 0];
        $relations = [];
        $primaryKeys = [];
        foreach ($databases as $name => $info) {
            $fingerprintSource[$name] = $info['tables'] ?? [];
            foreach ($totals as $key => $_) {
                $totals[$key] += (int) ($info[$key] ?? 0);
            }
            foreach ($info['relations'] ?? [] as $relation) {
                $relations[] = array_merge(['database' => $name], $relation);
            }
            foreach ($info['primary_keys'] ?? [] as $key) {
                $primaryKeys[] = array_merge(['database' => $name], $key);
            }
        }

        return [
            'server' => $server,
            'available_databases' => $available,
            'databases' => $databases,
            ...$totals,
            'relations' => $relations,
            'primary_keys' => $primaryKeys,
            'capabilities' => $capabilities,
            'fingerprint' => hash('sha256', json_encode($fingerprintSource, JSON_UNESCAPED_UNICODE)),
        ];
    }
}
