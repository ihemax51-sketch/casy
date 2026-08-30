<?php

namespace App\Services;

use App\Models\ServerProfile;
use PDO;
use RuntimeException;
use Throwable;

final class AreaStudioService
{
    public function __construct(private readonly SqlServerConnectionFactory $connections) {}

    public function workspace(ServerProfile $profile, ?int $sourceWorldId = null, string $regionSearch = ''): array
    {
        $pdo = $this->connect($profile);
        $worlds = $pdo->query(<<<'SQL'
SELECT w.*,
       (SELECT COUNT(*) FROM [dbo].[_RefInstance_World_Region] wr WHERE wr.[WorldID] = w.[ID]) AS region_count,
       (SELECT COUNT(*) FROM [dbo].[_RefInstance_World_Start_Pos] sp WHERE sp.[WorldID] = w.[ID]) AS start_count
FROM [dbo].[_RefGame_World] w
ORDER BY w.[ID]
SQL)->fetchAll();

        $world = null;
        if ($sourceWorldId !== null) {
            foreach ($worlds as $candidate) {
                if ((int) $candidate['ID'] === $sourceWorldId) {
                    $world = $candidate;
                    break;
                }
            }
            if (! $world) {
                throw new RuntimeException('The selected source world no longer exists.');
            }
        }

        $sourceRegions = [];
        $startPositions = [];
        if ($world) {
            $statement = $pdo->prepare(<<<'SQL'
SELECT r.[wRegionID] AS region_id, r.[ContinentName] AS continent_name, r.[AreaName] AS area_name,
       r.[X] AS sector_x, r.[Z] AS sector_z, r.[IsBattleField] AS is_battlefield, r.[Climate] AS climate
FROM [dbo].[_RefInstance_World_Region] wr
INNER JOIN [dbo].[_RefRegion] r ON r.[wRegionID] = wr.[RegionID]
WHERE wr.[WorldID] = ?
ORDER BY r.[wRegionID]
SQL);
            $statement->execute([$sourceWorldId]);
            $sourceRegions = $statement->fetchAll();

            $statement = $pdo->prepare('SELECT [WorldID], [RegionID], [PosX], [PosY], [PosZ], [Param] FROM [dbo].[_RefInstance_World_Start_Pos] WHERE [WorldID] = ? ORDER BY [RegionID], [PosX], [PosZ]');
            $statement->execute([$sourceWorldId]);
            $startPositions = $statement->fetchAll();
        }

        return [
            'worlds' => $worlds,
            'world' => $world,
            'source_regions' => $sourceRegions,
            'start_positions' => $startPositions,
            'regions' => [],
            'total_region_count' => (int) $pdo->query('SELECT COUNT_BIG(*) FROM [dbo].[_RefRegion]')->fetchColumn(),
            'next_world_id' => (int) $pdo->query('SELECT ISNULL(MAX([ID]), 0) + 1 FROM [dbo].[_RefGame_World]')->fetchColumn(),
        ];
    }

    public function createInstanceWorld(ServerProfile $profile, array $input): array
    {
        $pdo = $this->connect($profile);
        $regionIds = $this->normalizeRegionIds($input['region_ids'] ?? []);
        if (! $regionIds) {
            throw new RuntimeException('Select at least one terrain region for the new world.');
        }

        $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $pdo->beginTransaction();
        $identityInsert = false;

        try {
            $worldId = isset($input['new_world_id']) && $input['new_world_id'] !== null
                ? (int) $input['new_world_id']
                : (int) $pdo->query('SELECT ISNULL(MAX([ID]), 0) + 1 FROM [dbo].[_RefGame_World] WITH (UPDLOCK, HOLDLOCK)')->fetchColumn();
            $worldCode = trim((string) ($input['world_code'] ?? ''));
            if ($worldId < 1 || $worldCode === '') {
                throw new RuntimeException('A valid world ID and code name are required.');
            }

            $statement = $pdo->prepare('SELECT [ID], [WorldCodeName128] FROM [dbo].[_RefGame_World] WITH (UPDLOCK, HOLDLOCK) WHERE [ID] = ? OR [WorldCodeName128] = ?');
            $statement->execute([$worldId, $worldCode]);
            if ($conflict = $statement->fetch()) {
                $field = (int) $conflict['ID'] === $worldId ? 'ID' : 'code name';
                throw new RuntimeException("The requested world {$field} is already in use.");
            }

            $sourceWorld = null;
            $sourceWorldId = isset($input['source_world_id']) && $input['source_world_id'] !== null ? (int) $input['source_world_id'] : null;
            if (($input['clone_mode'] ?? 'quick') === 'full' && $sourceWorldId === null) {
                throw new RuntimeException('Full clone requires a source world so its verified dependencies can be mapped. Use Quick clone for a manual world.');
            }
            if ($sourceWorldId !== null) {
                $statement = $pdo->prepare('SELECT * FROM [dbo].[_RefGame_World] WITH (HOLDLOCK) WHERE [ID] = ?');
                $statement->execute([$sourceWorldId]);
                $sourceWorld = $statement->fetch();
                if (! $sourceWorld) {
                    throw new RuntimeException('The source world was not found. No changes were made.');
                }
            }

            $placeholders = implode(',', array_fill(0, count($regionIds), '?'));
            $statement = $pdo->prepare("SELECT [wRegionID] FROM [dbo].[_RefRegion] WITH (HOLDLOCK) WHERE [wRegionID] IN ({$placeholders})");
            $statement->execute($regionIds);
            $existingRegions = array_map('intval', array_column($statement->fetchAll(), 'wRegionID'));
            $missingRegions = array_values(array_diff($regionIds, $existingRegions));
            if ($missingRegions) {
                throw new RuntimeException('These regions do not have terrain rows: '.implode(', ', $missingRegions).'.');
            }

            $values = $this->worldValues($input, $sourceWorld);
            $isIdentity = (int) $pdo->query("SELECT COLUMNPROPERTY(OBJECT_ID(N'dbo._RefGame_World'), 'ID', 'IsIdentity')")->fetchColumn() === 1;
            if ($isIdentity) {
                $pdo->exec('SET IDENTITY_INSERT [dbo].[_RefGame_World] ON');
                $identityInsert = true;
            }

            $statement = $pdo->prepare('INSERT INTO [dbo].[_RefGame_World] ([ID], [WorldCodeName128], [Type], [WorldMaxCount], [WorldMaxUserCount], [WorldEntryType], [WorldEntranceType], [WorldLeaveType], [WorldDurationTime], [WorldEmptyRemainTime], [ConfigGroupCodeName128]) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $statement->execute([
                $worldId, $worldCode, $values['Type'], $values['WorldMaxCount'], $values['WorldMaxUserCount'],
                $values['WorldEntryType'], $values['WorldEntranceType'], $values['WorldLeaveType'],
                $values['WorldDurationTime'], $values['WorldEmptyRemainTime'], $values['ConfigGroupCodeName128'],
            ]);

            if ($identityInsert) {
                $pdo->exec('SET IDENTITY_INSERT [dbo].[_RefGame_World] OFF');
                $identityInsert = false;
            }

            $statement = $pdo->prepare('IF NOT EXISTS (SELECT 1 FROM [dbo].[_RefInstance_World_Region] WITH (UPDLOCK, HOLDLOCK) WHERE [WorldID] = ? AND [RegionID] = ?) INSERT INTO [dbo].[_RefInstance_World_Region] ([WorldID], [RegionID]) VALUES (?, ?)');
            foreach ($regionIds as $regionId) {
                $statement->execute([$worldId, $regionId, $worldId, $regionId]);
            }

            $startCount = 0;
            if (! empty($input['clone_start_positions']) && $sourceWorldId !== null) {
                $statement = $pdo->prepare('SELECT [RegionID], [PosX], [PosY], [PosZ], [Param] FROM [dbo].[_RefInstance_World_Start_Pos] WHERE [WorldID] = ?');
                $statement->execute([$sourceWorldId]);
                $starts = $statement->fetchAll();
                foreach ($starts as $start) {
                    if (! in_array((int) $start['RegionID'], $regionIds, true)) {
                        throw new RuntimeException('A copied start position belongs to region '.$start['RegionID'].', which is not assigned to the new world.');
                    }
                }
                $insertStart = $pdo->prepare('INSERT INTO [dbo].[_RefInstance_World_Start_Pos] ([WorldID], [RegionID], [PosX], [PosY], [PosZ], [Param]) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($starts as $start) {
                    $insertStart->execute([$worldId, $start['RegionID'], $start['PosX'], $start['PosY'], $start['PosZ'], $start['Param']]);
                    $startCount++;
                }
            }

            $dependencies = ['mode' => (string) ($input['clone_mode'] ?? 'quick'), 'teleports' => 0, 'tele_links' => 0, 'hives' => 0, 'nests' => 0, 'unsupported' => []];
            if ($dependencies['mode'] === 'full' && $sourceWorldId !== null) {
                $dependencies = array_merge($dependencies, $this->cloneWorldDependencies($pdo, $sourceWorldId, $worldId, $regionIds));
            }

            $pdo->commit();

            $resultInput = $input;
            $resultInput['new_world_id'] = $worldId;
            $resultInput['region_ids'] = $regionIds;

            return [
                'world_id' => $worldId,
                'world_code' => $worldCode,
                'region_count' => count($regionIds),
                'start_count' => $startCount,
                'dependencies' => $dependencies,
                'sql' => $this->previewSql($resultInput),
            ];
        } catch (Throwable $exception) {
            if ($identityInsert) {
                try {
                    $pdo->exec('SET IDENTITY_INSERT [dbo].[_RefGame_World] OFF');
                } catch (Throwable) {
                    // The transaction rollback below is still authoritative.
                }
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Update the configuration columns of an existing instance world.
     * Region mappings are deliberately managed by their own operation so a
     * harmless label change can never remove terrain from a live world.
     */
    public function updateInstanceWorld(ServerProfile $profile, int $worldId, array $input): array
    {
        $pdo = $this->connect($profile);
        $values = $this->worldValues($input, null);
        $worldCode = trim((string) ($input['world_code'] ?? ''));
        if ($worldId < 1 || $worldCode === '') {
            throw new RuntimeException('A valid world ID and code name are required.');
        }

        $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT [ID] FROM [dbo].[_RefGame_World] WITH (UPDLOCK, HOLDLOCK) WHERE [ID] = ?');
            $statement->execute([$worldId]);
            if (! $statement->fetch()) {
                throw new RuntimeException('The selected world no longer exists.');
            }

            $statement = $pdo->prepare('SELECT [ID] FROM [dbo].[_RefGame_World] WITH (UPDLOCK, HOLDLOCK) WHERE [WorldCodeName128] = ? AND [ID] <> ?');
            $statement->execute([$worldCode, $worldId]);
            if ($statement->fetch()) {
                throw new RuntimeException('That World CodeName is already in use.');
            }

            $statement = $pdo->prepare('UPDATE [dbo].[_RefGame_World] SET [WorldCodeName128] = ?, [Type] = ?, [WorldMaxCount] = ?, [WorldMaxUserCount] = ?, [WorldEntryType] = ?, [WorldEntranceType] = ?, [WorldLeaveType] = ?, [WorldDurationTime] = ?, [WorldEmptyRemainTime] = ?, [ConfigGroupCodeName128] = ? WHERE [ID] = ?');
            $statement->execute([
                $worldCode, $values['Type'], $values['WorldMaxCount'], $values['WorldMaxUserCount'],
                $values['WorldEntryType'], $values['WorldEntranceType'], $values['WorldLeaveType'],
                $values['WorldDurationTime'], $values['WorldEmptyRemainTime'], $values['ConfigGroupCodeName128'], $worldId,
            ]);
            $pdo->commit();

            return ['world_id' => $worldId, 'world_code' => $worldCode];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Add existing terrain mappings to a world without duplicating rows.
     */
    public function addRegionMappings(ServerProfile $profile, int $worldId, array|string $regionInput): array
    {
        $pdo = $this->connect($profile);
        $regionIds = $this->normalizeRegionIds($regionInput);
        if (! $regionIds) {
            throw new RuntimeException('Enter at least one terrain Region ID.');
        }

        $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT [ID] FROM [dbo].[_RefGame_World] WITH (UPDLOCK, HOLDLOCK) WHERE [ID] = ?');
            $statement->execute([$worldId]);
            if (! $statement->fetch()) {
                throw new RuntimeException('The selected world no longer exists.');
            }

            $placeholders = implode(',', array_fill(0, count($regionIds), '?'));
            $statement = $pdo->prepare("SELECT [wRegionID] FROM [dbo].[_RefRegion] WITH (HOLDLOCK) WHERE [wRegionID] IN ({$placeholders})");
            $statement->execute($regionIds);
            $existingRegions = array_map('intval', array_column($statement->fetchAll(), 'wRegionID'));
            $missingRegions = array_values(array_diff($regionIds, $existingRegions));
            if ($missingRegions) {
                throw new RuntimeException('These regions do not have terrain rows: '.implode(', ', $missingRegions).'.');
            }

            $checkMapping = $pdo->prepare('SELECT 1 FROM [dbo].[_RefInstance_World_Region] WITH (UPDLOCK, HOLDLOCK) WHERE [WorldID] = ? AND [RegionID] = ?');
            $insertMapping = $pdo->prepare('INSERT INTO [dbo].[_RefInstance_World_Region] ([WorldID], [RegionID]) VALUES (?, ?)');
            $added = 0;
            foreach ($regionIds as $regionId) {
                $checkMapping->execute([$worldId, $regionId]);
                if (! $checkMapping->fetch()) {
                    $insertMapping->execute([$worldId, $regionId]);
                    $added++;
                }
            }
            $pdo->commit();

            return ['world_id' => $worldId, 'requested' => count($regionIds), 'added' => $added];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Remove one terrain mapping and any starts attached to that mapping.
     */
    public function removeRegionMapping(ServerProfile $profile, int $worldId, int $regionId): array
    {
        $pdo = $this->connect($profile);
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT [ID] FROM [dbo].[_RefGame_World] WHERE [ID] = ?');
            $statement->execute([$worldId]);
            if (! $statement->fetch()) {
                throw new RuntimeException('The selected world no longer exists.');
            }

            $statement = $pdo->prepare('DELETE FROM [dbo].[_RefInstance_World_Start_Pos] WHERE [WorldID] = ? AND [RegionID] = ?');
            $statement->execute([$worldId, $regionId]);
            $startsRemoved = $statement->rowCount();
            $statement = $pdo->prepare('DELETE FROM [dbo].[_RefInstance_World_Region] WHERE [WorldID] = ? AND [RegionID] = ?');
            $statement->execute([$worldId, $regionId]);
            if ($statement->rowCount() === 0) {
                throw new RuntimeException('That region is not mapped to the selected world.');
            }
            $pdo->commit();

            return ['world_id' => $worldId, 'region_id' => $regionId, 'starts_removed' => $startsRemoved];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function previewSql(array $input): string
    {
        $worldId = $input['new_world_id'] ?? null;
        $worldCode = $this->sqlString((string) ($input['world_code'] ?? ''));
        $regionIds = $this->normalizeRegionIds($input['region_ids'] ?? []);
        $values = $this->worldValues($input, null);
        $sourceWorldId = isset($input['source_world_id']) && $input['source_world_id'] !== null ? (int) $input['source_world_id'] : null;
        $cloneMode = ($input['clone_mode'] ?? 'quick') === 'full' ? 'full' : 'quick';
        $regionList = $regionIds ? implode(', ', $regionIds) : '/* no regions selected */';

        $lines = [
            'SET NOCOUNT ON;',
            'SET XACT_ABORT ON;',
            'BEGIN TRY',
            '    BEGIN TRANSACTION;',
            '    -- CASY clone mode: '.strtoupper($cloneMode).'. Full mode additionally clones verified teleport and spawn dependencies.',
            '',
            '    DECLARE @WorldId INT;',
            '    DECLARE @WorldCode NVARCHAR(128) = N\''.$worldCode.'\';',
            '    DECLARE @IdentityInsert BIT = 0;',
            '',
            '    -- Allocate the next ID under a serializable lock when one was not supplied.',
            ($worldId === null || $worldId === '' ? '    SELECT @WorldId = ISNULL(MAX([ID]), 0) + 1 FROM [dbo].[_RefGame_World] WITH (UPDLOCK, HOLDLOCK);' : '    SET @WorldId = '.(int) $worldId.';'),
            '    IF @WorldId < 1 OR @WorldCode = N\'\' THROW 51000, \'A valid world ID and code name are required.\', 1;',
            '    IF EXISTS (SELECT 1 FROM [dbo].[_RefGame_World] WITH (UPDLOCK, HOLDLOCK) WHERE [ID] = @WorldId OR [WorldCodeName128] = @WorldCode)',
            '        THROW 51001, \'The requested world ID or code name is already in use.\', 1;',
            '',
            '    IF EXISTS (SELECT 1 FROM [dbo].[_RefRegion] WHERE [wRegionID] IN ('.$regionList.') HAVING COUNT(*) <> '.count($regionIds).')',
            '        THROW 51002, \'One or more selected regions do not have terrain rows.\', 1;',
            '',
            '    IF COLUMNPROPERTY(OBJECT_ID(N\'dbo._RefGame_World\'), N\'ID\', N\'IsIdentity\') = 1',
            '    BEGIN',
            '        SET IDENTITY_INSERT [dbo].[_RefGame_World] ON;',
            '        SET @IdentityInsert = 1;',
            '    END;',
            "    INSERT INTO [dbo].[_RefGame_World] ([ID], [WorldCodeName128], [Type], [WorldMaxCount], [WorldMaxUserCount], [WorldEntryType], [WorldEntranceType], [WorldLeaveType], [WorldDurationTime], [WorldEmptyRemainTime], [ConfigGroupCodeName128])",
            "    VALUES (@WorldId, @WorldCode, {$values['Type']}, {$values['WorldMaxCount']}, {$values['WorldMaxUserCount']}, {$values['WorldEntryType']}, {$values['WorldEntranceType']}, {$values['WorldLeaveType']}, {$values['WorldDurationTime']}, {$values['WorldEmptyRemainTime']}, N'".$this->sqlString($values['ConfigGroupCodeName128'])."');",
            '    IF @IdentityInsert = 1 SET IDENTITY_INSERT [dbo].[_RefGame_World] OFF;',
            '',
        ];
        foreach ($regionIds as $regionId) {
            $lines[] = "    INSERT INTO [dbo].[_RefInstance_World_Region] ([WorldID], [RegionID]) SELECT @WorldId, {$regionId} WHERE NOT EXISTS (SELECT 1 FROM [dbo].[_RefInstance_World_Region] WHERE [WorldID] = @WorldId AND [RegionID] = {$regionId});";
        }
        if (! empty($input['clone_start_positions']) && $sourceWorldId !== null) {
            $lines[] = '';
            $lines[] = '    -- Copy source start positions after validating their regions.';
            $lines[] = "    IF EXISTS (SELECT 1 FROM [dbo].[_RefInstance_World_Start_Pos] WHERE [WorldID] = {$sourceWorldId} AND [RegionID] NOT IN ({$regionList}))";
            $lines[] = "        THROW 51003, 'A copied start position belongs to a region that is not assigned to the new world.', 1;";
            $lines[] = '    INSERT INTO [dbo].[_RefInstance_World_Start_Pos] ([WorldID], [RegionID], [PosX], [PosY], [PosZ], [Param])';
            $lines[] = "    SELECT @WorldId, [RegionID], [PosX], [PosY], [PosZ], [Param] FROM [dbo].[_RefInstance_World_Start_Pos] WHERE [WorldID] = {$sourceWorldId};";
        }
        if ($cloneMode === 'full' && $sourceWorldId !== null) {
            $lines[] = '';
            $lines[] = '    -- Full dependency graph: _RefTeleport/_RefTeleLink and Tab_RefHive/Tab_RefNest with ID remapping.';
            $lines[] = '    -- CASY executes these rows through the typed adapter after a live schema capability check.';
        }
        array_push($lines, '', '    COMMIT TRANSACTION;', 'END TRY', 'BEGIN CATCH', '    IF @IdentityInsert = 1 SET IDENTITY_INSERT [dbo].[_RefGame_World] OFF;', '    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;', '    THROW;', 'END CATCH;');

        return implode("\n", $lines);
    }

    private function worldValues(array $input, ?array $sourceWorld): array
    {
        $defaults = $sourceWorld ?: [
            'Type' => 0, 'WorldMaxCount' => 1, 'WorldMaxUserCount' => 0,
            'WorldEntryType' => 0, 'WorldEntranceType' => 0, 'WorldLeaveType' => 0,
            'WorldDurationTime' => 0, 'WorldEmptyRemainTime' => 0, 'ConfigGroupCodeName128' => 'xxx',
        ];

        $mapping = [
            'Type' => 'type', 'WorldMaxCount' => 'max_count', 'WorldMaxUserCount' => 'max_users',
            'WorldEntryType' => 'entry_type', 'WorldEntranceType' => 'entrance_type',
            'WorldLeaveType' => 'leave_type', 'WorldDurationTime' => 'duration_time',
            'WorldEmptyRemainTime' => 'empty_remain_time', 'ConfigGroupCodeName128' => 'config_group',
        ];
        $values = [];
        foreach ($mapping as $column => $field) {
            $values[$column] = array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '' ? $input[$field] : $defaults[$column];
        }

        foreach (array_keys($mapping) as $column) {
            if ($column !== 'ConfigGroupCodeName128') {
                $values[$column] = (int) $values[$column];
            }
        }
        $values['ConfigGroupCodeName128'] = trim((string) $values['ConfigGroupCodeName128']) ?: 'xxx';

        return $values;
    }

    private function normalizeRegionIds(array|string $value): array
    {
        $parts = is_array($value) ? $value : preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        $ids = [];
        foreach ($parts ?: [] as $part) {
            if (! preg_match('/^-?\d+$/', (string) $part)) {
                throw new RuntimeException('Region IDs must be whole numbers separated by commas or spaces.');
            }
            $id = (int) $part;
            if ($id < -32768 || $id > 32767) {
                throw new RuntimeException("Region {$id} is outside the SQL smallint range.");
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    private function connect(ServerProfile $profile): PDO
    {
        if (! $profile->shard_database) {
            throw new RuntimeException('The shard database is not configured.');
        }

        return $this->connections->connect($profile, $profile->shard_database);
    }

    /** @return array<string, mixed> */
    private function cloneWorldDependencies(PDO $pdo, int $sourceWorldId, int $newWorldId, array $regionIds): array
    {
        $result = ['teleports' => 0, 'tele_links' => 0, 'hives' => 0, 'nests' => 0, 'unsupported' => []];
        // A full clone must be explicit about every dependency. If the schema
        // exposes an event/trigger table but CASY cannot prove its region
        // relationship, stop before inserting any dependency rows.
        if ($this->tableExists($pdo, '_RefEventZone') || $this->tableExists($pdo, '_RefTrigger')) {
            throw new RuntimeException('Full clone stopped: event and trigger dependencies need a verified schema relationship first. Use Quick clone or update the adapter.');
        }
        $teleportMap = [];
        if ($this->tableExists($pdo, '_RefTeleport')) {
            $columns = $this->tableColumns($pdo, '_RefTeleport');
            if (isset($columns['ID'], $columns['GenWorldID'], $columns['GenRegionID'])) {
                $ids = $regionIds;
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $statement = $pdo->prepare('SELECT * FROM [dbo].[_RefTeleport] WHERE ([GenWorldID] = ? OR [GenRegionID] IN ('.$placeholders.'))');
                $statement->execute(array_merge([$sourceWorldId], $ids));
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $overrides = ['GenWorldID' => $newWorldId];
                    if (isset($columns['CodeName128'])) {
                        $base = trim((string) ($row['CodeName128'] ?? 'TELEPORT')).'_W'.$newWorldId;
                        $overrides['CodeName128'] = $this->uniqueCode($pdo, '_RefTeleport', 'CodeName128', $base);
                    }
                    $newId = $this->insertCloneRow($pdo, '_RefTeleport', $columns, $row, $overrides, 'ID');
                    $teleportMap[(int) $row['ID']] = $newId;
                    $result['teleports']++;
                }
            }
        }
        if ($teleportMap && $this->tableExists($pdo, '_RefTeleLink')) {
            $columns = $this->tableColumns($pdo, '_RefTeleLink');
            $statement = $pdo->query('SELECT * FROM [dbo].[_RefTeleLink]');
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $owner = (int) ($row['OwnerTeleport'] ?? 0);
                $target = (int) ($row['TargetTeleport'] ?? 0);
                if (! isset($teleportMap[$owner], $teleportMap[$target])) {
                    continue;
                }
                $this->insertCloneRow($pdo, '_RefTeleLink', $columns, $row, ['OwnerTeleport' => $teleportMap[$owner], 'TargetTeleport' => $teleportMap[$target]], null);
                $result['tele_links']++;
            }
        }
        $hiveMap = [];
        if ($this->tableExists($pdo, 'Tab_RefHive')) {
            $columns = $this->tableColumns($pdo, 'Tab_RefHive');
            if (isset($columns['dwHiveID'], $columns['GameWorldID'])) {
                $statement = $pdo->prepare('SELECT * FROM [dbo].[Tab_RefHive] WHERE [GameWorldID] = ?');
                $statement->execute([$sourceWorldId]);
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $newId = $this->insertCloneRow($pdo, 'Tab_RefHive', $columns, $row, ['GameWorldID' => $newWorldId], 'dwHiveID');
                    $hiveMap[(int) $row['dwHiveID']] = $newId;
                    $result['hives']++;
                }
            }
        }
        if ($hiveMap && $this->tableExists($pdo, 'Tab_RefNest')) {
            $columns = $this->tableColumns($pdo, 'Tab_RefNest');
            if (isset($columns['dwNestID'], $columns['dwHiveID'])) {
                $statement = $pdo->query('SELECT * FROM [dbo].[Tab_RefNest]');
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $oldHive = (int) ($row['dwHiveID'] ?? 0);
                    if (! isset($hiveMap[$oldHive])) {
                        continue;
                    }
                    $this->insertCloneRow($pdo, 'Tab_RefNest', $columns, $row, ['dwHiveID' => $hiveMap[$oldHive]], 'dwNestID');
                    $result['nests']++;
                }
            }
        }
        return $result;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM sys.tables WHERE name = ?');
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function tableColumns(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare('SELECT c.name, t.name AS type_name, c.is_identity, c.is_computed FROM sys.columns c JOIN sys.types t ON t.user_type_id = c.user_type_id WHERE c.object_id = OBJECT_ID(?)');
        $statement->execute(['dbo.'.$table]);
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['name']] = $column;
        }
        return $columns;
    }

    private function insertCloneRow(PDO $pdo, string $table, array $columns, array $row, array $overrides = [], ?string $identityColumn = null): int
    {
        $blocked = ['timestamp', 'rowversion', 'image', 'text', 'ntext', 'xml', 'geography', 'geometry', 'hierarchyid'];
        $identityIsAuto = $identityColumn !== null && (bool) ($columns[$identityColumn]['is_identity'] ?? false);
        $allocatedIdentity = null;
        if ($identityColumn !== null && ! $identityIsAuto) {
            $allocatedIdentity = (int) $pdo->query(
                'SELECT ISNULL(MAX('.$this->connections->quoteIdentifier($identityColumn).'), 0) + 1 FROM [dbo].'.$this->connections->quoteIdentifier($table).' WITH (UPDLOCK, HOLDLOCK)'
            )->fetchColumn();
            if ($allocatedIdentity < 1) {
                throw new RuntimeException("Unable to allocate a new {$identityColumn} for {$table}.");
            }
        }
        $names = [];
        $values = [];
        foreach ($columns as $name => $meta) {
            if (($meta['is_identity'] ?? false) && $name === $identityColumn && $identityIsAuto) {
                continue;
            }
            if (($meta['is_identity'] ?? false) && $name !== $identityColumn) {
                continue;
            }
            if (($meta['is_computed'] ?? false) || in_array(strtolower((string) $meta['type_name']), $blocked, true)) {
                continue;
            }
            if (! array_key_exists($name, $row) && ! array_key_exists($name, $overrides)) {
                continue;
            }
            $names[] = $this->connections->quoteIdentifier($name);
            if ($name === $identityColumn && $allocatedIdentity !== null) {
                $values[] = $allocatedIdentity;
            } else {
                $values[] = array_key_exists($name, $overrides) ? $overrides[$name] : $row[$name];
            }
        }
        if (! $names) {
            throw new RuntimeException('No cloneable fields were found for '.$table.'.');
        }
        $sql = 'INSERT INTO [dbo].'.$this->connections->quoteIdentifier($table).' ('.implode(', ', $names).') VALUES ('.implode(', ', array_fill(0, count($names), '?')).')';
        $statement = $pdo->prepare($sql);
        $statement->execute($values);
        if (! $identityColumn) {
            return 0;
        }

        $newId = $identityIsAuto
            ? (int) $pdo->query('SELECT CAST(SCOPE_IDENTITY() AS int)')->fetchColumn()
            : (int) $allocatedIdentity;
        if ($newId < 1) {
            throw new RuntimeException("The cloned {$table} row did not return a valid identity.");
        }

        return $newId;
    }

    private function uniqueCode(PDO $pdo, string $table, string $column, string $base): string
    {
        $candidate = mb_substr($base, 0, 120);
        $counter = 1;
        $statement = $pdo->prepare('SELECT COUNT(*) FROM [dbo].'.$this->connections->quoteIdentifier($table).' WHERE '.$this->connections->quoteIdentifier($column).' = ?');
        while (true) {
            $statement->execute([$candidate]);
            if ((int) $statement->fetchColumn() === 0) {
                return $candidate;
            }
            $candidate = mb_substr($base, 0, 112).'_'.$counter++;
        }
    }

    private function sqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
