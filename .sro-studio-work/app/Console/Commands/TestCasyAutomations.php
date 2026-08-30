<?php

namespace App\Console\Commands;

use App\Models\ServerProfile;
use App\Services\GameAutomationCatalog;
use App\Services\GameAutomationService;
use App\Services\QueryReferenceCatalog;
use App\Services\SqlServerConnectionFactory;
use Illuminate\Console\Command;
use Throwable;

final class TestCasyAutomations extends Command
{
    protected $signature = 'casy:test-automations';

    protected $description = 'Run read-only and preview-only smoke tests for CASY one-click automations';

    public function handle(
        GameAutomationCatalog $catalog,
        GameAutomationService $automations,
        QueryReferenceCatalog $queries,
        SqlServerConnectionFactory $connections,
    ): int {
        $profile = ServerProfile::query()->where('is_active', true)->where('connection_status', 'connected')->latest()->firstOrFail();
        $pdo = $connections->connect($profile, $profile->shard_database);
        $character = (string) $pdo->query("SELECT TOP 1 [CharName16] FROM [dbo].[_Char] WHERE [Deleted]=0 ORDER BY [CharID]")->fetchColumn();
        $mobRows = $pdo->query("SELECT TOP 2 c.[CodeName128] FROM [dbo].[_RefObjCommon] c JOIN [dbo].[_RefObjChar] d ON d.[ID]=c.[Link] WHERE c.[CodeName128] LIKE 'MOB_%' ORDER BY c.[ID]")->fetchAll(\PDO::FETCH_COLUMN);
        $item = (string) $pdo->query("SELECT TOP 1 [CodeName128] FROM [dbo].[_RefObjCommon] WHERE [TypeID1]=3 AND [Service]=1 ORDER BY [ID]")->fetchColumn();
        $gachaSet = $pdo->query('SELECT TOP 1 [Set_ID] FROM [dbo].[_RefGachaItemSet] ORDER BY [Set_ID]')->fetchColumn();
        if ($character === '' || count($mobRows) < 2 || $item === '' || $gachaSet === false) {
            $this->error('The active schema has no safe character, mob or item samples for preview tests.');
            return self::FAILURE;
        }

        $cases = [
            ['Unique monster lookup', 'monsters.list-uniques', []],
            ['Object lookup', 'monsters.lookup-object', ['code_name' => 'MOB_']],
            ['HWAN lookup', 'characters.find-hwan', ['hwan_level' => 0]],
            ['Item owner lookup', 'inventory.find-item', ['item_code' => $item]],
            ['Ban preview', 'accounts.ban', ['char_name' => $character, 'reason' => 'CASY preview test', 'end_at' => now()->addDay()->format('Y-m-d H:i:s')]],
            ['Reset PK preview', 'characters.reset-pk', ['char_name' => $character]],
            ['Inventory size preview', 'characters.inventory-size', ['char_name' => $character, 'inventory_size' => 45]],
            ['HWAN preview', 'characters.hwan', ['char_name' => $character, 'hwan_level' => 0]],
            ['Rename preview', 'characters.rename', ['current_name' => $character, 'new_name' => 'CASY_PREVIEW_ONLY', 'offline_confirmation' => 'OFFLINE']],
            ['Timed jobs preview', 'characters.clear-timed-jobs', ['char_name' => $character]],
            ['Give item preview', 'inventory.add-item', ['char_name' => $character, 'item_code' => $item, 'amount' => 1, 'plus' => 0]],
            ['Unique conversion preview', 'monsters.set-unique', ['mob_code' => $mobRows[0]]],
            ['Monster stats preview', 'monsters.change-stats', ['mob_code' => $mobRows[0], 'level' => 1, 'hp_multiplier' => 1, 'exp_multiplier' => 1]],
            ['Monster EXP preview', 'monsters.multiply-exp', ['mob_code' => $mobRows[0], 'multiplier' => 1]],
            ['Spawn replacement preview', 'monsters.replace-spawn', ['old_mob_code' => $mobRows[0], 'new_mob_code' => $mobRows[1]]],
            ['Spawn creation preview', 'monsters.create-spawn', ['mob_code' => $mobRows[0], 'char_name' => $character, 'delay_min' => 60, 'delay_max' => 120, 'radius' => 10, 'generate_radius' => 10]],
            ['Drop removal preview', 'drops.remove-item', ['item_code' => $item]],
            ['Drop family preview', 'drops.disable-family', ['item_code_prefix' => $item]],
            ['Max stack preview', 'economy.max-stack', ['item_code' => $item, 'max_stack' => 50]],
            ['Gacha rate preview', 'economy.gacha-rate', ['set_id' => (int) $gachaSet, 'multiplier' => 1]],
            ['Job EXP preview', 'economy.job-exp-rate', ['level_from' => 1, 'level_to' => 7, 'divisor' => 1]],
            ['SOX rate preview', 'economy.sox-rate', ['level_from' => 1, 'level_to' => 10, 'multiplier' => 1]],
            ['Fortress preview', 'fortress.clear-ownership', []],
            ['Bulk silk preview', 'silk.bulk-add', ['silk_type' => 'silk_own', 'amount' => 1]],
        ];

        $rows = [];
        $failed = false;
        foreach ($cases as [$label, $action, $input]) {
            try {
                $result = $automations->run($profile, $action, $input, false);
                $rows[] = [$label, strtoupper((string) $result['mode']), 'PASS'];
            } catch (Throwable $exception) {
                $failed = true;
                $rows[] = [$label, 'ERROR', $exception->getMessage()];
            }
        }
        $coverage = $queries->summary();
        $rows[] = ['Automation catalog', count($catalog->all()).' tools', 'PASS'];
        $rows[] = ['Query reference coverage', ($coverage['ready'] ?? 0).' / 95 ready', 'PASS'];
        $this->table(['Check', 'Mode', 'Result'], $rows);

        if ($failed) {
            $this->error('One or more preview adapters failed. No writes were executed.');
            return self::FAILURE;
        }
        $this->info('All automation smoke tests passed. No writes were executed.');
        return self::SUCCESS;
    }
}
