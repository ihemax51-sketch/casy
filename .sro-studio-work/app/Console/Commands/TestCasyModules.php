<?php

namespace App\Console\Commands;

use App\Models\ServerProfile;
use App\Services\NpcShopCatalogService;
use App\Services\SroCatalogService;
use App\Services\SqlTableStudioService;
use App\Services\AreaStudioService;
use Illuminate\Console\Command;

class TestCasyModules extends Command
{
    protected $signature = 'casy:test-modules';

    protected $description = 'Run read-only smoke tests against the active live CASY modules';

    public function handle(NpcShopCatalogService $shops, SroCatalogService $catalog, SqlTableStudioService $tables, AreaStudioService $areas): int
    {
        $profile = ServerProfile::query()->where('is_active', true)->latest()->firstOrFail();
        $npc = $shops->workspace($profile);
        $items = $catalog->items($profile, '', 5);
        $itemRecord = $items ? $catalog->item($profile, (int) $items[0]['ID']) : null;
        $regions = $tables->browse($profile, $profile->shard_database, '_RefRegion');
        $events = $tables->browse($profile, $profile->shard_database, '_RefEvent', '', null, 1, 10);
        $triggers = $tables->browse($profile, $profile->shard_database, '_RefTriggerEvent', '', null, 1, 10);
        $scheduleDefinitions = $tables->browse($profile, $profile->shard_database, '_RefScheduleDefine', '', null, 1, 10);
        $schedules = $tables->browse($profile, $profile->shard_database, '_Schedule', '', null, 1, 10);
        $areaWorkspace = $areas->workspace($profile);

        $this->table(['Module', 'Result'], [
            ['Items sample', count($items)],
            ['Linked item details', $itemRecord && $itemRecord['linked'] ? count($itemRecord['linked']['columns']) : 0],
            ['NPC merchants', count($npc['npcs'])],
            ['Selected NPC groups', count($npc['groups'])],
            ['Selected NPC tabs', count($npc['tabs'])],
            ['Selected tab goods', count($npc['items'])],
            ['Universal table rows', count($regions['rows'])],
            ['Event definitions', $events['count']],
            ['Trigger events', $triggers['count']],
            ['Schedule definitions', $scheduleDefinitions['count']],
            ['Schedule rows', $schedules['count']],
            ['Instance worlds', count($areaWorkspace['worlds'])],
            ['Terrain region rows', $areaWorkspace['total_region_count'] ?? 0],
        ]);

        return self::SUCCESS;
    }
}
