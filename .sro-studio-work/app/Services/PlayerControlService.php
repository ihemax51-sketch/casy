<?php

namespace App\Services;

use App\Models\ServerProfile;
use PDO;
use RuntimeException;
use Throwable;

final class PlayerControlService
{
    private const ACTIONS = [
        ['key' => 'silk', 'group' => 'Character', 'label' => 'Add silk', 'description' => 'Choose normal, gift, or point silk.', 'procedures' => ['Live_Silk']],
        ['key' => 'level', 'group' => 'Character', 'label' => 'Change level', 'description' => 'Set the character level and refresh it.', 'procedures' => []],
        ['key' => 'gold', 'group' => 'Character', 'label' => 'Add gold', 'description' => 'Add gold through the live KMTGuard command.', 'procedures' => ['Live_Gold']],
        ['key' => 'stats', 'group' => 'Character', 'label' => 'Add STR / INT', 'description' => 'Increase one character stat and refresh it.', 'procedures' => []],
        ['key' => 'skill_points', 'group' => 'Character', 'label' => 'Add skill points', 'description' => 'Increase available skill points and refresh.', 'procedures' => []],
        ['key' => 'position', 'group' => 'Movement', 'label' => 'Teleport to position', 'description' => 'Move the player to world, region, and coordinates.', 'procedures' => ['Teleport_Position']],
        ['key' => 'town', 'group' => 'Movement', 'label' => 'Return to town', 'description' => 'Send the player to its configured town.', 'procedures' => ['Teleport_PlayerToTown|Teleport_2Town']],
        ['key' => 'refresh', 'group' => 'Movement', 'label' => 'Self teleport', 'description' => 'Reload the player at the same location.', 'procedures' => ['Teleport_Self']],
        ['key' => 'player_notice', 'group' => 'Communication', 'label' => 'Player notice', 'description' => 'Send a private notice to one character.', 'procedures' => ['Command_NoticeByID|Command_NoticeByName']],
        ['key' => 'server_notice', 'group' => 'Communication', 'label' => 'Server notice', 'description' => 'Broadcast a notice to the server.', 'procedures' => ['Command_NoticeAll']],
        ['key' => 'disconnect', 'group' => 'Communication', 'label' => 'Disconnect player', 'description' => 'Close one character session.', 'procedures' => ['Command_DisconnectByID|Command_DisconnectByName']],
        ['key' => 'spawn', 'group' => 'Rewards & Spawn', 'label' => 'Spawn unique', 'description' => 'Spawn a monster near a player or at a position.', 'procedures' => ['NPC_SpawnNearPlayer|NPC_SpawnAtPosition']],
        ['key' => 'item_player', 'group' => 'Rewards & Spawn', 'label' => 'Item to player', 'description' => 'Send an item to one player Item Chest.', 'procedures' => ['Item_AddChestByCodeName']],
        ['key' => 'item_online', 'group' => 'Rewards & Spawn', 'label' => 'Item to online', 'description' => 'Send an item to every online player.', 'procedures' => ['Item_ChestSendToOnline']],
    ];

    public function __construct(
        private readonly SqlServerConnectionFactory $connections,
        private readonly KmtGuardCommandService $commands,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function actions(ServerProfile $profile): array
    {
        $available = array_fill_keys(array_column($this->commands->procedures($profile), 'name'), true);

        return array_map(function (array $action) use ($available): array {
            $action['available'] = true;
            foreach ($action['procedures'] as $requirement) {
                $choices = explode('|', $requirement);
                if (! array_filter($choices, static fn (string $name): bool => isset($available[$name]))) {
                    $action['available'] = false;
                    break;
                }
            }

            return $action;
        }, self::ACTIONS);
    }

    /** @return array<string, mixed> */
    public function character(ServerProfile $profile, string $mode, string $value): array
    {
        $value = trim($value);
        if (! in_array($mode, ['id', 'name'], true) || $value === '') {
            throw new RuntimeException('Enter a CharID or exact character name.');
        }
        if ($mode === 'id' && (! preg_match('/^\d+$/', $value) || (int) $value <= 0)) {
            throw new RuntimeException('CharID must be a positive whole number.');
        }

        $column = $mode === 'id' ? 'CharID' : 'CharName16';
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $statement = $pdo->prepare(<<<SQL
SELECT TOP (1)
    [CharID], [CharName16], [CurLevel], [MaxLevel], [RemainGold],
    [Strength], [Intellect], [RemainSkillPoint], [WorldID],
    [LatestRegion], [PosX], [PosY], [PosZ]
FROM [dbo].[_Char]
WHERE [$column] = ? AND [Deleted] = 0
SQL);
        $statement->execute([$mode === 'id' ? (int) $value : $value]);
        $character = $statement->fetch(PDO::FETCH_ASSOC);
        if (! $character) {
            throw new RuntimeException('Character was not found. Check the CharID or exact name.');
        }

        return $this->normalizeCharacter($character);
    }

    public function requiresTarget(string $action, array $input = []): bool
    {
        return ! in_array($action, ['server_notice', 'item_online'], true)
            && ! ($action === 'spawn' && ($input['spawn_mode'] ?? 'player') === 'position');
    }

    /** @return array<string, mixed> */
    public function execute(ServerProfile $profile, string $action, string $targetMode, string $targetValue, array $input): array
    {
        if (! in_array($action, array_column(self::ACTIONS, 'key'), true)) {
            throw new RuntimeException('Choose one of the available actions.');
        }

        $character = $this->requiresTarget($action, $input)
            ? $this->character($profile, $targetMode, $targetValue)
            : null;
        $charId = (string) ($character['id'] ?? '');

        return match ($action) {
            'level' => $this->mutateCharacter($profile, $character, 'level', (int) $input['level']),
            'stats' => $this->mutateCharacter($profile, $character, (string) $input['stat_type'], (int) $input['amount']),
            'skill_points' => $this->mutateCharacter($profile, $character, 'skill_points', (int) $input['amount']),
            'silk' => $this->commandResult(
                $this->commands->execute($profile, 'Live_Silk', [
                    'nSilk' => $input['silk_type'] === 'normal' ? $input['amount'] : 0,
                    'nSilkGift' => $input['silk_type'] === 'gift' ? $input['amount'] : 0,
                    'nSilkPoint' => $input['silk_type'] === 'point' ? $input['amount'] : 0,
                ], 'id', $charId),
                $character,
                'Silk was added successfully.'
            ),
            'gold' => $this->commandResult(
                $this->commands->execute($profile, 'Live_Gold', ['Gold' => $input['amount'], 'AddOrRemove' => 1], 'id', $charId),
                $character,
                'Gold was queued successfully.'
            ),
            'position' => $this->commandResult(
                $this->commands->execute($profile, 'Teleport_Position', [
                    'GameWorldID' => $input['world_id'], 'RegionId' => $input['region_id'],
                    'PosX' => $input['pos_x'], 'PosY' => $input['pos_y'], 'PosZ' => $input['pos_z'],
                ], 'id', $charId),
                $character,
                'The player teleport was queued successfully.'
            ),
            'town' => $this->commandResult(
                $this->commands->execute($profile, $this->firstProcedure($profile, ['Teleport_PlayerToTown', 'Teleport_2Town']), [], 'id', $charId),
                $character,
                'The player was sent to town successfully.'
            ),
            'refresh' => $this->commandResult(
                $this->commands->execute($profile, 'Teleport_Self', [], 'id', $charId),
                $character,
                'Self teleport was requested successfully.'
            ),
            'player_notice' => $this->commandResult(
                $this->commands->execute($profile, $this->firstProcedure($profile, ['Command_NoticeByID', 'Command_NoticeByName']), [
                    'NoticeType' => $input['notice_type'], 'Notice' => $input['notice'],
                ], 'id', $charId),
                $character,
                'The notice was sent to the player.'
            ),
            'server_notice' => $this->commandResult(
                $this->commands->execute($profile, 'Command_NoticeAll', [
                    'NoticeType' => $input['notice_type'], 'Notice' => $input['notice'],
                ]),
                null,
                'The server notice was queued successfully.'
            ),
            'disconnect' => $this->commandResult(
                $this->commands->execute($profile, $this->firstProcedure($profile, ['Command_DisconnectByID', 'Command_DisconnectByName']), [], 'id', $charId),
                $character,
                'The player disconnect was requested.'
            ),
            'spawn' => $this->spawn($profile, $character, $input),
            'item_player' => $this->commandResult(
                $this->commands->execute($profile, 'Item_AddChestByCodeName', [
                    'ItemCodeName' => $input['item_code'], 'Quantity' => $input['quantity'],
                    'From' => 'CASY', 'Plus' => $input['plus'],
                ], 'id', $charId),
                $character,
                'The item was added to the player Item Chest.'
            ),
            'item_online' => $this->commandResult(
                $this->commands->execute($profile, 'Item_ChestSendToOnline', [
                    'ItemCodeName' => $input['item_code'], 'Quantity' => $input['quantity'],
                    'From' => 'CASY', 'Plus' => $input['plus'],
                ]),
                null,
                'The item reward was queued for online players.'
            ),
        };
    }

    /** @return array<string, mixed> */
    private function spawn(ServerProfile $profile, ?array $character, array $input): array
    {
        if ($input['spawn_mode'] === 'position') {
            $command = $this->commands->execute($profile, 'NPC_SpawnAtPosition', [
                'MonsterID' => $input['monster_id'], 'GameWorldID' => $input['world_id'],
                'RegionId' => $input['region_id'], 'PosX' => $input['pos_x'],
                'PosY' => $input['pos_y'], 'PosZ' => $input['pos_z'],
                'GenerateRadius' => $input['radius'],
            ]);
        } else {
            $command = $this->commands->execute($profile, 'NPC_SpawnNearPlayer', [
                'MonsterID' => $input['monster_id'],
            ], 'id', (string) $character['id']);
        }

        return $this->commandResult($command, $character, 'The monster spawn was queued successfully.');
    }

    /** @return array<string, mixed> */
    private function mutateCharacter(ServerProfile $profile, array $character, string $mutation, int $value): array
    {
        $pdo = $this->connections->connect($profile, $profile->shard_database);
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare(<<<'SQL'
SELECT [CharID], [CharName16], [CurLevel], [MaxLevel], [RemainGold],
       [Strength], [Intellect], [RemainSkillPoint], [WorldID],
       [LatestRegion], [PosX], [PosY], [PosZ]
FROM [dbo].[_Char] WITH (UPDLOCK, HOLDLOCK)
WHERE [CharID] = ? AND [Deleted] = 0
SQL);
            $lock->execute([(int) $character['id']]);
            $beforeRow = $lock->fetch(PDO::FETCH_ASSOC);
            if (! $beforeRow) {
                throw new RuntimeException('The character no longer exists.');
            }

            $before = $this->normalizeCharacter($beforeRow);
            [$sql, $parameters] = match ($mutation) {
                'level' => [
                    'UPDATE [dbo].[_Char] SET [CurLevel] = ?, [MaxLevel] = ? WHERE [CharID] = ?',
                    [$value, $value, (int) $character['id']],
                ],
                'strength' => $this->incrementStatement('Strength', (int) $before['strength'], $value, 32767, (int) $character['id']),
                'intellect' => $this->incrementStatement('Intellect', (int) $before['intellect'], $value, 32767, (int) $character['id']),
                'skill_points' => $this->incrementStatement('RemainSkillPoint', (int) $before['skill_points'], $value, 2147483647, (int) $character['id']),
                default => throw new RuntimeException('Unsupported character mutation.'),
            };

            $update = $pdo->prepare($sql);
            $update->execute($parameters);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The character update did not affect exactly one row.');
            }

            $after = $this->characterByIdOnConnection($pdo, (int) $character['id']);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $warning = null;
        $refreshCommand = null;
        try {
            $refreshCommand = $this->commands->execute($profile, 'Teleport_Self', [], 'id', (string) $character['id']);
        } catch (Throwable $exception) {
            $warning = 'The database update succeeded, but self teleport could not be queued: '.$exception->getMessage();
        }

        $label = match ($mutation) {
            'level' => 'Character level was updated.',
            'strength' => 'Strength was added.',
            'intellect' => 'Intelligence was added.',
            'skill_points' => 'Skill points were added.',
        };

        return [
            'message' => $label.' Self teleport refresh was requested.',
            'warning' => $warning,
            'character' => $after,
            'before' => $before,
            'after' => $after,
            'procedure' => $refreshCommand['procedure'] ?? null,
            'generated_sql' => $sql,
            'command' => $refreshCommand,
        ];
    }

    /** @return array{0:string,1:array<int, int>} */
    private function incrementStatement(string $column, int $current, int $amount, int $maximum, int $charId): array
    {
        if ($amount <= 0 || $current > $maximum - $amount) {
            throw new RuntimeException($column.' would exceed its SQL Server value limit.');
        }

        return [
            'UPDATE [dbo].[_Char] SET ['.$column.'] = ? WHERE [CharID] = ?',
            [$current + $amount, $charId],
        ];
    }

    /** @return array<string, mixed> */
    private function characterByIdOnConnection(PDO $pdo, int $charId): array
    {
        $statement = $pdo->prepare(<<<'SQL'
SELECT [CharID], [CharName16], [CurLevel], [MaxLevel], [RemainGold],
       [Strength], [Intellect], [RemainSkillPoint], [WorldID],
       [LatestRegion], [PosX], [PosY], [PosZ]
FROM [dbo].[_Char]
WHERE [CharID] = ?
SQL);
        $statement->execute([$charId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (! $row) {
            throw new RuntimeException('Character could not be reloaded after the update.');
        }

        return $this->normalizeCharacter($row);
    }

    /** @return array<string, mixed> */
    private function normalizeCharacter(array $row): array
    {
        return [
            'id' => (int) $row['CharID'],
            'name' => (string) $row['CharName16'],
            'level' => (int) $row['CurLevel'],
            'max_level' => (int) $row['MaxLevel'],
            'gold' => (string) $row['RemainGold'],
            'strength' => (int) $row['Strength'],
            'intellect' => (int) $row['Intellect'],
            'skill_points' => (int) $row['RemainSkillPoint'],
            'world_id' => (int) $row['WorldID'],
            'region_id' => (int) $row['LatestRegion'],
            'pos_x' => (float) $row['PosX'],
            'pos_y' => (float) $row['PosY'],
            'pos_z' => (float) $row['PosZ'],
        ];
    }

    /** @return array<string, mixed> */
    private function commandResult(array $command, ?array $character, string $message): array
    {
        return [
            'message' => $message,
            'warning' => null,
            'character' => $character,
            'before' => [],
            'after' => [],
            'procedure' => $command['procedure'],
            'generated_sql' => $command['sql'],
            'command' => $command,
        ];
    }

    /** @param array<int, string> $choices */
    private function firstProcedure(ServerProfile $profile, array $choices): string
    {
        $available = array_fill_keys(array_column($this->commands->procedures($profile), 'name'), true);
        foreach ($choices as $choice) {
            if (isset($available[$choice])) {
                return $choice;
            }
        }

        throw new RuntimeException('The required KMTGuard procedure is not installed.');
    }
}
