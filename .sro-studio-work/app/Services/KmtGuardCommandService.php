<?php

namespace App\Services;

use App\Models\ServerProfile;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * The web studio is deliberately a client of KMTGuard's command contract.
 * It may execute an approved KMTGuard procedure, but it never writes a
 * command queue row itself. The Filter remains the only command dispatcher.
 */
final class KmtGuardCommandService
{
    private const SUPPORTED_PROCEDURES = [
        'Live_Silk',
        'Live_Gold',
        // Optional Filter extension. It is shown only when the connected
        // KMTGUARD database actually exposes this official procedure.
        'Live_Level',
        'Teleport_Self',
        'Teleport_Position',
        'Teleport_PlayerToTown',
        'Teleport_2Town',
        'Command_NoticeByID',
        'Command_NoticeByName',
        'Command_NoticeAll',
        'Command_DisconnectByID',
        'Command_DisconnectByName',
        'NPC_SpawnAtPosition',
        'NPC_SpawnNearPlayer',
        'Item_AddChestByCodeName',
        'Item_ChestSendToOnline',
        'Item_ChestSendToAll',
    ];

    /** @return array<string, array<string, mixed>> */
    public function uiCatalog(): array
    {
        return config('casy.live_procedures', []);
    }

    private const INTERNAL_PROCEDURES = [
        'Command_ClaimGameServer',
        'Command_CompleteGameServer',
        'Command_RetryGameServer',
    ];

    public function __construct(private readonly SqlServerConnectionFactory $connections) {}

    /** @return array<int, array<string, mixed>> */
    public function procedures(ServerProfile $profile): array
    {
        $pdo = $this->connectProxy($profile);
        $statement = $pdo->query(<<<'SQL'
SELECT
    p.name AS proc_name,
    prm.parameter_id,
    prm.name AS parameter_name,
    TYPE_NAME(prm.user_type_id) AS type_name,
    prm.max_length,
    prm.precision,
    prm.scale,
    prm.is_output,
    prm.has_default_value,
    CONVERT(nvarchar(max), m.definition) AS definition
FROM sys.procedures AS p
LEFT JOIN sys.parameters AS prm ON prm.object_id = p.object_id
LEFT JOIN sys.sql_modules AS m ON m.object_id = p.object_id
WHERE p.schema_id = SCHEMA_ID(N'dbo')
  AND p.is_ms_shipped = 0
  AND (
      p.name LIKE N'Live[_]%'
      OR p.name LIKE N'Command[_]%'
      OR p.name LIKE N'Teleport[_]%'
      OR p.name LIKE N'NPC[_]%'
      OR p.name LIKE N'Item[_]%'
  )
ORDER BY p.name, prm.parameter_id;
SQL);

        $grouped = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) ($row['proc_name'] ?? '');
            if (! $this->isAllowedProcedureName($name)) {
                continue;
            }

            if (! isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'label' => $this->label($name),
                    'parameters' => [],
                    'definition' => (string) ($row['definition'] ?? ''),
                ];
            }

            if ($row['parameter_id'] !== null && ! (bool) $row['is_output']) {
                $parameterName = ltrim((string) $row['parameter_name'], '@');
                if (! preg_match('/^[A-Za-z0-9_]+$/', $parameterName)) {
                    continue;
                }

                $grouped[$name]['parameters'][] = [
                    'name' => $parameterName,
                    'label' => $this->label($parameterName),
                    'type' => strtolower((string) ($row['type_name'] ?? 'nvarchar')),
                    'max_length' => (int) ($row['max_length'] ?? -1),
                    'precision' => (int) ($row['precision'] ?? 0),
                    'scale' => (int) ($row['scale'] ?? 0),
                    'optional' => (bool) ($row['has_default_value'] ?? false)
                        || ($name === 'Item_AddChestByCodeName' && strcasecmp($parameterName, 'BatchID') === 0),
                ];
            }
        }

        $ordered = array_values($grouped);
        $order = array_flip(self::SUPPORTED_PROCEDURES);
        usort($ordered, static fn (array $left, array $right) => ($order[$left['name']] ?? 999) <=> ($order[$right['name']] ?? 999));

        return $ordered;
    }

    /**
     * Execute one official KMTGuard procedure and return the queue rows that
     * appeared immediately after it. The procedure itself owns validation,
     * transactions, and queue insertion.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(
        ServerProfile $profile,
        string $procedure,
        array $input,
        ?string $targetMode = null,
        ?string $targetValue = null,
    ): array
    {
        $catalog = $this->procedures($profile);
        $definition = collect($catalog)->firstWhere('name', $procedure);
        if (! is_array($definition)) {
            throw new RuntimeException('That KMTGuard command procedure is not available on the connected server.');
        }

        $parameters = $definition['parameters'];
        $targetParameter = collect($parameters)->first(
            fn (array $parameter) => in_array(strtolower($parameter['name']), ['charid', 'charname16'], true)
        );
        if ($targetParameter) {
            $input[$targetParameter['name']] = $this->resolveTarget(
                $profile,
                (string) ($targetMode ?: 'id'),
                (string) ($targetValue ?: ''),
                strtolower($targetParameter['name']) === 'charid',
            );
        }
        $known = [];
        foreach ($parameters as $parameter) {
            $known[$parameter['name']] = $parameter;
        }

        foreach (array_keys($input) as $key) {
            if (! is_string($key) || ! isset($known[$key])) {
                throw new RuntimeException('The command contains an unknown parameter. Refresh the command catalog and try again.');
            }
        }

        $bindings = [];
        $displayParameters = [];
        foreach ($parameters as $parameter) {
            $name = $parameter['name'];
            $value = $input[$name] ?? null;
            if (($value === null || $value === '') && $parameter['optional']) {
                continue;
            }
            if ($value === null || $value === '') {
                throw new RuntimeException($parameter['label'].' is required.');
            }

            $converted = $this->convertValue($parameter, $value);
            $bindings[] = $converted;
            $displayParameters[$name] = is_scalar($converted) ? (string) $converted : '';
        }

        $pdo = $this->connectProxy($profile);
        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $parts = [];
        foreach ($displayParameters as $name => $value) {
            $parts[] = '@'.$name.' = '.($value === '' ? 'NULL' : "'".str_replace("'", "''", $value)."'");
        }
        $sql = 'EXEC [dbo].'.$this->connections->quoteIdentifier($procedure).($parts ? ' '.implode(', ', $parts) : '');

        $placeholders = [];
        foreach ($displayParameters as $name => $_) {
            $placeholders[] = '@'.$name.' = ?';
        }
        $executeSql = 'EXEC [dbo].'.$this->connections->quoteIdentifier($procedure).($placeholders ? ' '.implode(', ', $placeholders) : '');
        $statement = $pdo->prepare($executeSql);
        $statement->execute($bindings);

        $resultSets = [];
        do {
            if ($statement->columnCount() > 0) {
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                if ($rows !== []) {
                    $resultSets[] = $rows;
                }
            }
        } while ($statement->nextRowset());
        $statement->closeCursor();

        $observed = $this->recentQueueRows($pdo, $startedAt);

        return [
            'procedure' => $procedure,
            'label' => $definition['label'],
            'parameters' => $displayParameters,
            'target_mode' => $targetMode,
            'target_value' => $targetValue,
            'sql' => $sql,
            'started_at' => $startedAt->format(DATE_ATOM),
            'queues' => $observed,
            'result_sets' => $resultSets,
        ];
    }

    /** @return array<string, mixed> */
    public function dashboard(ServerProfile $profile, ?string $status = null, ?string $search = null): array
    {
        $pdo = $this->connectProxy($profile);
        $logs = $this->queueLogs($pdo, $status, $search);

        $counts = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        ];
        foreach ($logs as $log) {
            $bucket = match ($log['state']) {
                'pending', 'retry' => 'pending',
                'processing' => 'processing',
                'completed' => 'completed',
                'failed' => 'failed',
                default => null,
            };
            if ($bucket !== null) {
                $counts[$bucket]++;
            }
        }

        return [
            'procedures' => $this->procedures($profile),
            'logs' => $logs,
            'counts' => $counts,
        ];
    }

    private function connectProxy(ServerProfile $profile): PDO
    {
        if (! $profile->proxy_database) {
            throw new RuntimeException('The active server has no KMTGuard database configured.');
        }

        return $this->connections->connect($profile, $profile->proxy_database);
    }

    private function isAllowedProcedureName(string $name): bool
    {
        return in_array($name, self::SUPPORTED_PROCEDURES, true)
            && ! in_array($name, self::INTERNAL_PROCEDURES, true);
    }

    private function resolveTarget(ServerProfile $profile, string $mode, string $value, bool $charIdParameter): string
    {
        if (! in_array($mode, ['id', 'name'], true) || $value === '') {
            throw new RuntimeException('Choose a CharID or player name before sending this command.');
        }

        if ($charIdParameter && $mode === 'id') {
            if (! preg_match('/^\d+$/', $value) || (int) $value <= 0) {
                throw new RuntimeException('CharID must be a positive whole number.');
            }

            return $value;
        }

        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $column = $mode === 'id' ? 'CharID' : 'CharName16';
        $statement = $pdo->prepare(
            'SELECT TOP 1 [CharID], [CharName16] FROM [dbo].[_Char] WHERE ['.$column.'] = ?'
        );
        $statement->execute([$mode === 'id' ? (int) $value : $value]);
        $character = $statement->fetch(PDO::FETCH_ASSOC);
        if (! $character) {
            throw new RuntimeException('The player was not found in SRO_VT_SHARD._Char.');
        }

        return $charIdParameter ? (string) $character['CharID'] : (string) $character['CharName16'];
    }

    private function label(string $name): string
    {
        $labels = [
            'Live_Silk' => 'Add / remove silk',
            'Live_Gold' => 'Add / remove gold',
            'Live_Level' => 'Update player level',
            'Teleport_Self' => 'Self teleport',
            'Teleport_Position' => 'Teleport to position',
            'Teleport_PlayerToTown' => 'Return player to town',
            'Teleport_2Town' => 'Return player to town (safe)',
            'Command_NoticeByID' => 'Notice player by ID',
            'Command_NoticeByName' => 'Notice player by name',
            'Command_NoticeAll' => 'Send server notice',
            'Command_DisconnectByID' => 'Disconnect player by ID',
            'Command_DisconnectByName' => 'Disconnect player by name',
            'NPC_SpawnAtPosition' => 'Spawn monster at position',
            'NPC_SpawnNearPlayer' => 'Spawn monster near player',
            'Item_AddChestByCodeName' => 'Send item to player chest',
            'Item_ChestSendToOnline' => 'Send item to online players',
            'Item_ChestSendToAll' => 'Send item to all characters',
            'nSilk' => 'Normal silk',
            'nSilkGift' => 'Gift silk',
            'nSilkPoint' => 'Silk points',
            'AddOrRemove' => 'Operation',
            'SkillCodeName' => 'Skill code name',
            'SkillID' => 'Skill ID',
            'NoticeType' => 'Notice type',
            'GameWorldID' => 'Game world ID',
            'RegionId' => 'Region ID',
            'PosX' => 'Position X',
            'PosY' => 'Position Y',
            'PosZ' => 'Position Z',
            'FreezeSeconds' => 'Freeze seconds',
            'WorldID' => 'World ID',
            'Level' => 'New level',
            'NewLevel' => 'New level',
            'Gold' => 'Gold amount',
            'MonsterID' => 'Monster ID',
            'GenerateRadius' => 'Spawn radius',
            'ItemCodeName' => 'Item CodeName',
            'Quantity' => 'Quantity',
            'From' => 'Reward source',
            'Plus' => 'Plus',
        ];
        if (isset($labels[$name])) {
            return $labels[$name];
        }

        $name = preg_replace('/^(Live|Command)_/', '', $name) ?: $name;

        return trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $name));
    }

    private function convertValue(array $parameter, mixed $value): mixed
    {
        $type = strtolower((string) $parameter['type']);
        $text = trim((string) $value);

        if (in_array($type, ['bit'], true)) {
            $normalized = strtolower($text);
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return 1;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return 0;
            }
            throw new RuntimeException($parameter['label'].' must be true or false.');
        }

        if (in_array($type, ['tinyint', 'smallint', 'int', 'bigint'], true)) {
            if (! preg_match('/^-?\d+$/', $text)) {
                throw new RuntimeException($parameter['label'].' must be a whole number.');
            }
            if ($type !== 'bigint' && filter_var($text, FILTER_VALIDATE_INT) === false) {
                throw new RuntimeException($parameter['label'].' is outside the valid range.');
            }
            if ($type === 'tinyint' && ((int) $text < 0 || (int) $text > 255)) {
                throw new RuntimeException($parameter['label'].' must be between 0 and 255.');
            }

            return $text;
        }

        if (in_array($type, ['decimal', 'numeric', 'money', 'smallmoney', 'float', 'real'], true)) {
            if (! preg_match('/^-?(?:\d+(?:\.\d+)?|\.\d+)$/', $text)) {
                throw new RuntimeException($parameter['label'].' must be numeric.');
            }

            return $text;
        }

        if (in_array($type, ['uniqueidentifier'], true) && ! preg_match('/^[0-9a-f-]{36}$/i', $text)) {
            throw new RuntimeException($parameter['label'].' must be a valid identifier.');
        }

        $maxLength = (int) $parameter['max_length'];
        if ($maxLength > 0) {
            $characters = in_array($type, ['nvarchar', 'nchar'], true) ? intdiv($maxLength, 2) : $maxLength;
            if (mb_strlen($text) > $characters) {
                throw new RuntimeException($parameter['label'].' is too long for the KMTGuard procedure.');
            }
        }

        return $text;
    }

    /** @return array<int, array<string, mixed>> */
    private function recentQueueRows(PDO $pdo, DateTimeImmutable $startedAt): array
    {
        $since = $startedAt->modify('-2 seconds')->format('Y-m-d H:i:s.v');
        $rows = [];
        $rows = array_merge($rows, $this->queryIfTableExists($pdo, 'Command_FilterQueue', <<<SQL
SELECT TOP 25 ID, CommandID AS command_id, Status AS status_code,
       CreatedUtc AS created_at, CompletedUtc AS completed_at,
       Attempts AS attempts, LastError AS last_error, IdempotencyKey AS idempotency_key,
       Data1, Data2, Data3, Data4, Data5, Data6, Data7, Data8, Data9, Data10, Data11
FROM dbo.Command_FilterQueue
WHERE CreatedUtc >= ?
ORDER BY ID DESC
SQL, [$since], 'filter'));
        $rows = array_merge($rows, $this->queryIfTableExists($pdo, 'Command_GameServerQueue', <<<SQL
SELECT TOP 25 ID, Action_ID AS command_id, ClaimState AS status_code,
       NULL AS created_at, NULL AS completed_at,
       AttemptCount AS attempts, LastError AS last_error, NULL AS idempotency_key,
       Data1, Data2, Data3, Data4, Data5, Data6, Data7, Data8, Data9, Data10, Data11
FROM dbo.Command_GameServerQueue
WHERE ClaimedAtUtc >= ? OR (ClaimedAtUtc IS NULL AND ID >= (SELECT ISNULL(MAX(ID), 0) - 25 FROM dbo.Command_GameServerQueue))
ORDER BY ID DESC
SQL, [$since], 'gameserver'));

        foreach ($rows as &$row) {
            $row['sort_at'] = (string) ($row['created_at'] ?: $row['completed_at'] ?: '');
        }
        unset($row);

        usort($rows, fn (array $left, array $right) => strcmp((string) $right['sort_at'], (string) $left['sort_at']));

        return array_slice($rows, 0, 50);
    }

    /** @return array<int, array<string, mixed>> */
    private function queueLogs(PDO $pdo, ?string $status, ?string $search): array
    {
        $rows = [];
        $rows = array_merge($rows, $this->queryIfTableExists($pdo, 'Command_FilterQueue', <<<SQL
SELECT TOP 100 ID, CommandID AS command_id, Status AS status_code,
       CreatedUtc AS created_at, CompletedUtc AS completed_at,
       Attempts AS attempts, LastError AS last_error, IdempotencyKey AS idempotency_key,
       Data1, Data2, Data3, Data4, Data5, Data6, Data7, Data8, Data9, Data10, Data11
FROM dbo.Command_FilterQueue
ORDER BY ID DESC
SQL, [], 'filter'));
        $rows = array_merge($rows, $this->queryIfTableExists($pdo, 'Command_GameServerQueue', <<<SQL
SELECT TOP 100 ID, Action_ID AS command_id, ClaimState AS status_code,
       NULL AS created_at, NULL AS completed_at,
       AttemptCount AS attempts, LastError AS last_error, NULL AS idempotency_key,
       Data1, Data2, Data3, Data4, Data5, Data6, Data7, Data8, Data9, Data10, Data11
FROM dbo.Command_GameServerQueue
ORDER BY ID DESC
SQL, [], 'gameserver'));

        $results = $this->queryIfTableExists($pdo, 'Command_GameServerResult', <<<'SQL'
SELECT TOP 100 CommandID AS ID, ActionID AS command_id,
       Status AS result_status, ResultCode AS result_code,
       CompletedAtUtc AS completed_at, Reason AS last_error,
       AttemptCount AS attempts
FROM dbo.Command_GameServerResult
ORDER BY CompletedAtUtc DESC
SQL, [], 'gameserver-result');
        foreach ($results as $result) {
            $result['created_at'] = $result['completed_at'];
            $result['status_code'] = $result['result_status'];
            $result['payload'] = [];
            $result['queue'] = 'GameServer result';
            $result['state'] = match ((string) $result['result_status']) {
                'Dispatched' => 'completed',
                'Rejected', 'Failed', 'Indeterminate' => 'failed',
                default => 'processing',
            };
            $result['sort_at'] = (string) $result['completed_at'];
            $rows[] = $result;
        }

        $normalized = [];
        foreach ($rows as $row) {
            $payload = [];
            foreach (range(1, 11) as $index) {
                $key = 'Data'.$index;
                if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                    $payload[$key] = (string) $row[$key];
                }
            }
            $row['payload'] = $row['payload'] ?? $payload;
            $row['queue'] = $row['queue'] ?? ($row['source'] === 'filter' ? 'Filter queue' : 'GameServer queue');
            $row['state'] = $row['state'] ?? match ((int) $row['status_code']) {
                0 => 'completed',
                1 => 'pending',
                2 => 'processing',
                3 => 'retry',
                4 => 'failed',
                default => 'processing',
            };
            $row['sort_at'] = $row['sort_at'] ?? (string) ($row['completed_at'] ?: $row['created_at'] ?: '');
            $row['status_label'] = $row['result_status'] ?? ucfirst((string) $row['state']);

            if ($status && $status !== $row['state']) {
                continue;
            }
            if ($search !== '') {
                $haystack = strtolower($row['queue'].' '.$row['command_id'].' '.json_encode($row['payload']).' '.($row['last_error'] ?? ''));
                if (! str_contains($haystack, strtolower($search))) {
                    continue;
                }
            }
            $normalized[] = $row;
        }

        usort($normalized, fn (array $left, array $right) => strcmp((string) $right['sort_at'], (string) $left['sort_at']));

        return array_slice($normalized, 0, 150);
    }

    /** @return array<int, array<string, mixed>> */
    private function queryIfTableExists(PDO $pdo, string $table, string $sql, array $bindings, string $source): array
    {
        $check = $pdo->prepare('SELECT CASE WHEN OBJECT_ID(?, N\'U\') IS NULL THEN 0 ELSE 1 END');
        $check->execute(['dbo.'.$table]);
        if ((int) $check->fetchColumn() !== 1) {
            return [];
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['source'] = $source;
            $rows[] = $row;
        }

        return $rows;
    }
}
