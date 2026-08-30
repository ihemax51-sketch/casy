<?php

namespace App\Http\Controllers;

use App\Services\GameAutomationCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class StudioModuleController extends Controller
{
    public function __invoke(Request $request, string $module, GameAutomationCatalog $automations): View
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->first();

        return view('studio.module', [
            'profile' => $profile,
            'module' => $module,
            'moduleTitle' => str($module)->replace('-', ' ')->title(),
            'moduleMeta' => $this->moduleMeta($module),
            'automations' => $automations->forModule($module),
            'automationAction' => session('automation_action') ?: $request->query('action'),
            'automationResult' => session('automation_result'),
        ]);
    }

    /** @return array{eyebrow:string,description:string,actions:array<int,string>} */
    private function moduleMeta(string $module): array
    {
        return [
            'accounts' => ['eyebrow' => 'Player administration', 'description' => 'Find accounts, linked characters and access records in one place.', 'actions' => ['Account lookup', 'Ban review', 'Silk balance']],
            'characters' => ['eyebrow' => 'Player administration', 'description' => 'Inspect a character profile, progression, location and owned content.', 'actions' => ['Profile', 'Progression', 'Location']],
            'inventory' => ['eyebrow' => 'Player administration', 'description' => 'Trace item ownership across inventory, avatar, storage and pets.', 'actions' => ['Owner lookup', 'Move item', 'Audit item']],
            'skills' => ['eyebrow' => 'Player administration', 'description' => 'Review skills and masteries with the character they belong to.', 'actions' => ['Masteries', 'Skill state', 'Reset preview']],
            'guilds' => ['eyebrow' => 'Player administration', 'description' => 'Manage guild members, alliances, permissions and fortress links.', 'actions' => ['Guilds', 'Members', 'Alliances']],
            'silk' => ['eyebrow' => 'Player administration', 'description' => 'Review normal silk, gift silk and silk points with an account context.', 'actions' => ['Normal silk', 'Gift silk', 'Silk points']],
            'monsters' => ['eyebrow' => 'World content', 'description' => 'Inspect monster definitions and their Hive, Nest and Tactics spawn graph.', 'actions' => ['Definitions', 'Spawns', 'Unique mobs']],
            'drops' => ['eyebrow' => 'World content', 'description' => 'Trace drop assignments and rates without editing raw SQL.', 'actions' => ['Assignments', 'Rates', 'Impact preview']],
            'teleports' => ['eyebrow' => 'World content', 'description' => 'Explore teleport endpoints, links, world IDs and destination rules.', 'actions' => ['Endpoints', 'Links', 'Restrictions']],
            'quests' => ['eyebrow' => 'World content', 'description' => 'Inspect quest definitions, rewards and character progress.', 'actions' => ['Definitions', 'Rewards', 'Progress']],
            'events' => ['eyebrow' => 'World content', 'description' => 'Review event, trigger and reward relationships detected in this schema.', 'actions' => ['Events', 'Triggers', 'Schedules']],
            'fortress' => ['eyebrow' => 'World content', 'description' => 'Inspect fortress ownership, structures, records and guild relationships.', 'actions' => ['Ownership', 'Structures', 'War records']],
            'item-mall' => ['eyebrow' => 'Content studio', 'description' => 'Review item mall packages, tabs, categories and sale goods.', 'actions' => ['Packages', 'Categories', 'Goods']],
            'economy' => ['eyebrow' => 'Operations', 'description' => 'Review rates, price policies, packages and economy tables before a guarded change.', 'actions' => ['Rates', 'Policies', 'Gacha']],
            'logs' => ['eyebrow' => 'Operations', 'description' => 'Search the detected log tables and correlate them with CASY audit actions.', 'actions' => ['Search', 'Export', 'Audit']],
        ][$module] ?? ['eyebrow' => 'CASY studio', 'description' => 'Explore the verified records available for this workspace.', 'actions' => ['Browse', 'Inspect', 'Audit']];
    }

}
