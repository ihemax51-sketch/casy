<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\IconAsset;
use App\Services\SroCatalogService;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function __invoke(SroCatalogService $catalog): View
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->first();
        $metrics = ['items' => null, 'characters' => null, 'npcs' => null, 'guilds' => null, 'worlds' => null, 'icons' => 0];

        if ($profile && $profile->connection_status === 'connected') {
            try {
                $metrics['items'] = $catalog->count($profile, '_RefObjCommon', '[TypeID1] = 3');
                $metrics['characters'] = $catalog->count($profile, '_Char');
                $metrics['npcs'] = $catalog->count($profile, '_RefObjCommon', '[TypeID1] = 2');
                $metrics['guilds'] = $catalog->count($profile, '_Guild');
                $metrics['worlds'] = $catalog->count($profile, '_RefGame_World');
            } catch (Throwable) {
                // The dashboard remains usable while a game database is offline.
            }
        }

        if ($profile) {
            $metrics['icons'] = IconAsset::where('server_profile_id', $profile->id)->count();
        }

        return view('dashboard', [
            'profile' => $profile,
            'metrics' => $metrics,
            'recentActions' => ActionLog::where('user_id', auth()->id())->latest()->limit(6)->get(),
        ]);
    }
}
