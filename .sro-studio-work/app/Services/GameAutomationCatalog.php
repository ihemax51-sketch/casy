<?php

namespace App\Services;

use RuntimeException;

final class GameAutomationCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return [
            'accounts.ban' => $this->action('accounts', [5, 78], 'Ban player', 'Resolve the player account and create the matching punishment and blocked-user records.', 'high', [
                $this->field('char_name', 'Player name'),
                $this->field('reason', 'Ban reason', 'text', 'required|string|max:128'),
                $this->field('end_at', 'Ban ends at', 'datetime-local', 'required|date|after:now'),
            ]),
            'accounts.unban' => $this->action('accounts', [79], 'Remove player ban', 'Resolve the account from the player name and remove its ban records.', 'high', [
                $this->field('char_name', 'Player name'),
            ]),
            'accounts.rename-login' => $this->action('accounts', [90], 'Change account login ID', 'Rename an account login after checking that the new ID is free.', 'high', [
                $this->field('current_login', 'Current login ID'),
                $this->field('new_login', 'New login ID'),
            ]),
            'silk.bulk-add' => $this->action('silk', [4, 75], 'Add silk to all accounts', 'Create missing silk rows and add one selected currency type to every account.', 'critical', [
                $this->field('silk_type', 'Silk type', 'select', 'required|in:silk_own,silk_gift,silk_point', ['silk_own' => 'Normal silk', 'silk_gift' => 'Gift silk', 'silk_point' => 'Silk points']),
                $this->field('amount', 'Amount per account', 'number', 'required|integer|min:1|max:2000000000'),
            ]),
            'characters.reset-pk' => $this->action('characters', [6, 56], 'Reset PK status', 'Clear PK counters and restore the normal HWAN allowance for one player.', 'high', [
                $this->field('char_name', 'Player name'),
            ]),
            'characters.inventory-size' => $this->action('characters', [11], 'Set inventory size', 'Change one character inventory capacity with the database limit enforced.', 'medium', [
                $this->field('char_name', 'Player name'),
                $this->field('inventory_size', 'Inventory slots', 'number', 'required|integer|min:13|max:109'),
            ]),
            'characters.hwan' => $this->action('characters', [10, 57], 'Assign HWAN title', 'Assign an existing HWAN title level to one player.', 'medium', [
                $this->field('char_name', 'Player name'),
                $this->field('hwan_level', 'HWAN level', 'number', 'required|integer|min:0|max:255'),
            ]),
            'characters.find-hwan' => $this->action('characters', [58], 'Find players by HWAN', 'List players currently using a selected HWAN title.', 'low', [
                $this->field('hwan_level', 'HWAN level', 'number', 'required|integer|min:0|max:255'),
            ], true),
            'characters.rename' => $this->action('characters', [86], 'Rename offline character', 'Rename one character after confirming the player is offline.', 'high', [
                $this->field('current_name', 'Current player name'),
                $this->field('new_name', 'New player name'),
                $this->field('offline_confirmation', 'Type OFFLINE', 'text', 'required|in:OFFLINE'),
            ]),
            'characters.clear-timed-jobs' => $this->action('characters', [55], 'Clear stuck timed jobs', 'Remove every timed-job row attached to one exact character after showing the affected records.', 'critical', [
                $this->field('char_name', 'Player name'),
            ]),
            'inventory.find-item' => $this->action('inventory', [1], 'Find item owners', 'Search player inventories by item CodeName and show the exact owner, slot, item ID and plus.', 'low', [
                $this->field('item_code', 'Item CodeName'),
            ], true),
            'inventory.add-item' => $this->action('inventory', [61], 'Give item to player', 'Use the verified shard procedure to add an item by CodeName.', 'high', [
                $this->field('char_name', 'Player name'),
                $this->field('item_code', 'Item CodeName'),
                $this->field('amount', 'Amount', 'number', 'required|integer|min:1|max:10000'),
                $this->field('plus', 'Plus level', 'number', 'required|integer|min:0|max:255'),
            ]),
            'skills.toggle' => $this->action('skills', [68], 'Enable or disable skill', 'Change the Service state for one exact skill code.', 'high', [
                $this->field('skill_code', 'Skill Basic_Code'),
                $this->field('service', 'State', 'select', 'required|in:0,1', ['1' => 'Enabled', '0' => 'Disabled']),
            ]),
            'monsters.lookup-object' => $this->action('monsters', [64, 82], 'Find object or NPC ID', 'Look up object IDs by CodeName without writing to the database.', 'low', [
                $this->field('code_name', 'CodeName contains'),
            ], true),
            'monsters.list-uniques' => $this->action('monsters', [87], 'List unique monsters', 'List active monster definitions with Rarity 3.', 'low', [], true),
            'monsters.set-unique' => $this->action('monsters', [29, 63], 'Convert mob to unique', 'Set Rarity to 3 for one exact monster CodeName.', 'high', [
                $this->field('mob_code', 'Monster CodeName'),
            ]),
            'monsters.change-stats' => $this->action('monsters', [28], 'Change monster level and scaling', 'Set the level and apply controlled HP and EXP multipliers.', 'high', [
                $this->field('mob_code', 'Monster CodeName'),
                $this->field('level', 'New level', 'number', 'required|integer|min:1|max:255'),
                $this->field('hp_multiplier', 'HP multiplier', 'number', 'required|numeric|min:0.01|max:100', [], 'Example: 1.10'),
                $this->field('exp_multiplier', 'EXP multiplier', 'number', 'required|numeric|min:0.01|max:100', [], 'Example: 1.10'),
            ]),
            'monsters.multiply-exp' => $this->action('monsters', [89], 'Multiply monster EXP', 'Multiply EXP for one exact mob or unique and show the before/after value.', 'high', [
                $this->field('mob_code', 'Monster CodeName'),
                $this->field('multiplier', 'EXP multiplier', 'number', 'required|numeric|min:0.01|max:100'),
            ]),
            'monsters.replace-spawn' => $this->action('monsters', [24], 'Replace monster in spawns', 'Replace all tactics using one monster with another verified monster.', 'high', [
                $this->field('old_mob_code', 'Current monster CodeName'),
                $this->field('new_mob_code', 'Replacement CodeName'),
            ]),
            'monsters.create-spawn' => $this->action('monsters', [25, 72], 'Create fixed unique spawn', 'Create a Hive, Tactics and Nest graph at an existing character position.', 'critical', [
                $this->field('mob_code', 'Monster CodeName'),
                $this->field('char_name', 'Position from player'),
                $this->field('delay_min', 'Minimum respawn seconds', 'number', 'required|integer|min:1|max:2147483647'),
                $this->field('delay_max', 'Maximum respawn seconds', 'number', 'required|integer|min:1|max:2147483647|gte:delay_min'),
                $this->field('radius', 'Movement radius', 'number', 'required|integer|min:0|max:100000'),
                $this->field('generate_radius', 'Generate radius', 'number', 'required|integer|min:0|max:100000'),
            ]),
            'monsters.spawn-settings' => $this->action('monsters', [27, 83, 93, 95], 'Edit spawn timing and count', 'Update every Nest connected to one exact monster CodeName.', 'high', [
                $this->field('mob_code', 'Monster CodeName'),
                $this->field('delay_min', 'Minimum respawn seconds', 'number', 'required|integer|min:1|max:2147483647'),
                $this->field('delay_max', 'Maximum respawn seconds', 'number', 'required|integer|min:1|max:2147483647|gte:delay_min'),
                $this->field('max_count', 'Maximum alive count', 'number', 'required|integer|min:0|max:100000'),
            ]),
            'drops.assign' => $this->action('drops', [22, 73], 'Assign item drop', 'Attach one item to one mob or unique with amount, plus and probability controls.', 'high', [
                $this->field('mob_code', 'Monster CodeName'),
                $this->field('item_code', 'Item CodeName'),
                $this->field('drop_ratio', 'Drop ratio', 'number', 'required|numeric|min:0|max:1', [], '1 = 100%, 0.3 = 30%'),
                $this->field('amount_min', 'Minimum amount', 'number', 'required|integer|min:1|max:255'),
                $this->field('amount_max', 'Maximum amount', 'number', 'required|integer|min:1|max:255|gte:amount_min'),
                $this->field('plus', 'Plus level', 'number', 'required|integer|min:0|max:255'),
            ]),
            'drops.remove-item' => $this->action('drops', [67], 'Remove item drop assignments', 'Remove all standard drop assignments for one exact item CodeName.', 'critical', [
                $this->field('item_code', 'Item CodeName'),
            ]),
            'drops.disable-family' => $this->action('drops', [23, 26, 50, 80, 92], 'Disable a complete drop family', 'Disable standard drop rows for items matching one explicit CodeName prefix, such as ITEM_EU or ITEM_ETC_ARCHEMY.', 'critical', [
                $this->field('item_code_prefix', 'Item CodeName prefix', 'text', 'required|string|max:64|regex:/^ITEM_[A-Z0-9_]+$/', [], 'Exact prefix only; CASY previews every affected row first.'),
            ]),
            'economy.max-stack' => $this->action('economy', [34, 46], 'Change one item max stack', 'Set MaxStack for one exact item CodeName and show the media-sync requirement.', 'high', [
                $this->field('item_code', 'Item CodeName'),
                $this->field('max_stack', 'Maximum stack', 'number', 'required|integer|min:1|max:50000'),
            ]),
            'economy.gacha-rate' => $this->action('economy', [35, 76], 'Scale one Magic Pop set', 'Multiply ratios for one Gacha Set_ID while enforcing the SQL SMALLINT limit.', 'critical', [
                $this->field('set_id', 'Gacha Set ID', 'number', 'required|integer|min:0|max:2147483647'),
                $this->field('multiplier', 'Ratio multiplier', 'number', 'required|numeric|min:0.01|max:100', [], 'Example: 2 doubles the current ratios.'),
            ]),
            'economy.job-exp-rate' => $this->action('economy', [33], 'Scale job EXP thresholds', 'Divide Trader, Hunter and Robber job thresholds for a controlled level range.', 'critical', [
                $this->field('level_from', 'Level from', 'number', 'required|integer|min:1|max:7'),
                $this->field('level_to', 'Level to', 'number', 'required|integer|min:1|max:7|gte:level_from'),
                $this->field('divisor', 'Threshold divisor', 'number', 'required|numeric|min:0.01|max:1000', [], 'Higher divisor means faster job progression.'),
            ]),
            'economy.sox-rate' => $this->action('economy', [30], 'Scale SOX drop groups', 'Multiply rare-equipment probability groups for an explicit monster-level range.', 'critical', [
                $this->field('level_from', 'Monster level from', 'number', 'required|integer|min:1|max:255'),
                $this->field('level_to', 'Monster level to', 'number', 'required|integer|min:1|max:255|gte:level_from'),
                $this->field('multiplier', 'Probability multiplier', 'number', 'required|numeric|min:0.01|max:100'),
            ]),
            'monsters.fix-fortress-uniques' => $this->action('monsters', [88], 'Repair fortress unique classification', 'Preview the known fortress mob families, then set their type and rarity classification.', 'high', []),
            'fortress.clear-ownership' => $this->action('fortress', [21, 62], 'Remove fortress ownership', 'Set the owner GuildID to zero for every fortress after previewing the affected rows.', 'critical', []),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function forModule(string $module): array
    {
        return array_filter($this->all(), fn (array $action) => $action['module'] === $module);
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        $action = $this->all()[$key] ?? null;
        if (! $action) {
            throw new RuntimeException('Unknown CASY automation.');
        }
        return $action;
    }

    private function action(string $module, array $queryRefs, string $title, string $description, string $risk, array $fields, bool $readOnly = false): array
    {
        return compact('module', 'queryRefs', 'title', 'description', 'risk', 'fields', 'readOnly');
    }

    private function field(string $name, string $label, string $type = 'text', string $rules = 'required|string|max:128', array $options = [], string $hint = ''): array
    {
        return compact('name', 'label', 'type', 'rules', 'options', 'hint');
    }
}
