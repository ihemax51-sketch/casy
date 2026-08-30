<?php

namespace App\Services;

final class QueryReferenceCatalog
{
    public function __construct(private readonly GameAutomationCatalog $automations) {}

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $titles = [
            1 => 'Search for an item on a character',
            2 => 'Teleport all players to a character',
            3 => 'Delete an item from all players',
            4 => 'Add silk to all players',
            5 => 'Ban a player',
            6 => 'Remove PK by character name',
            7 => 'Give 120 mastery scroll',
            8 => 'Move all players to Jangan',
            9 => 'Give academy buff',
            10 => 'Give title name',
            11 => 'Give inventory size',
            12 => 'Open all Chinese skills for GM',
            13 => 'Give avatar to GM',
            14 => 'Clean account database',
            15 => 'Clean shard database',
            16 => 'Clean shard log database',
            17 => 'Disable guild and job penalty time',
            18 => 'Create guild at level 5',
            19 => 'Change guild member limit',
            20 => 'Change union limit',
            21 => 'Remove fortress ownership from all guilds',
            22 => 'Add a drop to a unique or mob',
            23 => 'Delete alchemy material drops',
            24 => 'Change a unique spawn monster',
            25 => 'Add a unique spawn',
            26 => 'Delete a drop',
            27 => 'Change unique respawn delay',
            28 => 'Change mob or unique level',
            29 => 'Change a mob to unique',
            30 => 'Change SOX drop rate',
            31 => 'Change alchemy rate',
            32 => 'Change lucky powder rate',
            33 => 'Change job EXP rate',
            34 => 'Change job gold rate',
            35 => 'Change Magic Pop rate',
            36 => 'Change stone rate',
            37 => 'Fix F1 problem',
            38 => 'Fix five-page pet inventory',
            39 => 'Fix seven-page pet inventory',
            40 => 'Rebuild honor rank',
            41 => 'Fix pills',
            42 => 'Apply fortress war fixes',
            43 => 'Add a custom teleport',
            44 => 'Add degree 12 or 13 items',
            45 => 'Add level 120 skills',
            46 => 'Change item maximum stack',
            47 => 'Generate Media.pk2 SQL data',
            48 => 'Generate itemdata media rows',
            49 => 'Generate characterdata media rows',
            50 => 'Remove European drops',
            51 => 'Disable European content',
            52 => 'Add a new NPC',
            53 => 'Add a new shop group',
            54 => 'Add a new shop tab',
            55 => 'Remove premium or timed jobs from a character',
            56 => 'Reset PK status for a character',
            57 => 'Assign a HWAN title to a character',
            58 => 'Find characters by HWAN title',
            59 => 'Remove an item from all characters',
            60 => 'Move all characters to Jangan',
            61 => 'Add an item to a character',
            62 => 'Remove fortress ownership from all guilds',
            63 => 'Convert a mob to unique rarity',
            64 => 'Find an object ID by CodeName',
            65 => 'Disable guild or job timed penalties',
            66 => 'Unlock Chinese skills for a character',
            67 => 'Delete item drop assignments by item code',
            68 => 'Enable or disable a skill definition',
            69 => 'Clean the account database',
            70 => 'Clean the shard database',
            71 => 'Clean the shard log database',
            72 => 'Create a fixed unique spawn at a character position',
            73 => 'Assign an item drop to a mob or unique',
            74 => 'Create a custom teleport',
            75 => 'Add silk to all accounts',
            76 => 'Change Magic Pop (Gacha) rate',
            77 => 'Change pills cooldown parameters',
            78 => 'Ban a player by character name',
            79 => 'Remove a player ban',
            80 => 'Disable tablet or material drops',
            81 => 'Detach character skill rows',
            82 => 'Look up an NPC ID by CodeName',
            83 => 'Clear a mob spawn position',
            84 => 'Reset character skills and restore points',
            85 => 'Toggle battlefield or safe-zone state',
            86 => 'Rename a character',
            87 => 'List all unique mob CodeNames',
            88 => 'Fix fortress unique classification',
            89 => 'Multiply EXP for a mob or unique',
            90 => 'Change an account login ID',
            91 => 'Add a new HWAN title definition',
            92 => 'Remove European item drops',
            93 => 'Fix FGW unique or Envy spawn counts',
            94 => 'Change guild member limit',
            95 => 'Fix Holy Water Temple unique spawn counts',
        ];

        $actionByReference = [];
        foreach ($this->automations->all() as $key => $action) {
            foreach ($action['queryRefs'] as $reference) {
                $actionByReference[$reference] = ['key' => $key] + $action;
            }
        }

        $emergency = [2, 3, 8, 14, 15, 16, 59, 60, 69, 70, 71, 81];
        $packageOnly = [7, 9, 12, 13, 17, 18, 19, 20, 37, 38, 39, 40, 41, 42, 44, 45, 47, 48, 49, 51, 65, 66, 77, 84, 88, 91, 94];
        $specialized = [52 => ['npc-shops.index', 'NPC Shops'], 53 => ['npc-shops.index', 'NPC Shops'], 54 => ['npc-shops.index', 'NPC Shops']];
        $rows = [];
        foreach ($titles as $number => $title) {
            $action = $actionByReference[$number] ?? null;
            $status = 'review';
            $statusLabel = 'Needs reviewed adapter';
            $module = null;
            $actionKey = null;
            $link = null;
            if ($action) {
                $status = 'ready';
                $statusLabel = 'One-click tool ready';
                $module = $action['module'];
                $actionKey = $action['key'];
                $link = route('studio.module', ['module' => $module, 'action' => $action['key']]);
            } elseif (isset($specialized[$number])) {
                $status = 'ready';
                $statusLabel = 'Covered by specialized studio';
                $link = route($specialized[$number][0]);
                $module = $specialized[$number][1];
            } elseif ($number === 85) {
                $status = 'incompatible';
                $statusLabel = 'Table missing from this schema';
            } elseif (in_array($number, $emergency, true)) {
                $status = 'emergency';
                $statusLabel = 'Emergency-only / intentionally locked';
            } elseif (in_array($number, $packageOnly, true)) {
                $status = 'package';
                $statusLabel = 'Requires reviewed server/media package';
            }
            $rows[] = compact('number', 'title', 'status', 'statusLabel', 'module', 'actionKey', 'link') + ['category' => $this->category($number)];
        }

        return $rows;
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $counts = array_count_values(array_column($this->all(), 'status'));
        return ['total' => 95] + $counts;
    }

    private function category(int $number): string
    {
        return match (true) {
            $number <= 13 => 'Players & rewards',
            $number <= 16 => 'Maintenance',
            $number <= 21 => 'Guilds & fortress',
            $number <= 29 => 'Monsters, spawns & drops',
            $number <= 36 => 'Economy & rates',
            $number <= 45 => 'Server & content fixes',
            $number <= 54 => 'Items, media & NPCs',
            $number <= 68 => 'Players & world content',
            $number <= 71 => 'Maintenance',
            $number <= 77 => 'World & economy',
            $number <= 84 => 'Accounts & skills',
            $number === 85 => 'Regions',
            $number <= 91 => 'Players & content',
            default => 'World content',
        };
    }
}
