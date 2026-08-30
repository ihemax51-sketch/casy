<?php

namespace App\Console\Commands;

use App\Models\ServerProfile;
use App\Services\KmtGuardCommandService;
use App\Services\PlayerControlService;
use App\Services\SqlServerConnectionFactory;
use App\Services\SroCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\ViewErrorBag;
use PDO;

final class TestCasyPlayerControl extends Command
{
    protected $signature = 'casy:test-player-control';

    protected $description = 'Run read-only contract checks for Player Control and its search helpers';

    public function handle(
        SqlServerConnectionFactory $connections,
        KmtGuardCommandService $commands,
        PlayerControlService $control,
        SroCatalogService $catalog,
    ): int {
        $profile = ServerProfile::query()->where('is_active', true)->where('connection_status', 'connected')->latest()->firstOrFail();
        $pdo = $connections->connect($profile, $profile->shard_database);
        $requiredColumns = ['CharID', 'CharName16', 'Deleted', 'CurLevel', 'MaxLevel', 'RemainGold', 'Strength', 'Intellect', 'RemainSkillPoint'];
        $placeholders = implode(',', array_fill(0, count($requiredColumns), '?'));
        $statement = $pdo->prepare('SELECT [name] FROM sys.columns WHERE object_id = OBJECT_ID(N\'dbo._Char\') AND [name] IN ('.$placeholders.')');
        $statement->execute($requiredColumns);
        $foundColumns = $statement->fetchAll(PDO::FETCH_COLUMN);
        $missingColumns = array_values(array_diff($requiredColumns, $foundColumns));

        $procedures = array_column($commands->procedures($profile), 'name');
        $actions = $control->actions($profile);
        $items = $catalog->items($profile, 'ITEM_', 2);
        $monsters = $catalog->monsters($profile, 'MOB_', 2);
        auth()->login($profile->user);
        view()->share('errors', new ViewErrorBag());
        $rendered = view('studio.live.index', [
            'profile' => $profile,
            'actions' => $actions,
            'character' => null,
            'error' => null,
        ])->render();

        $this->table(['Action', 'Ready'], array_map(static fn (array $action): array => [
            $action['label'], $action['available'] ? 'yes' : 'no',
        ], $actions));
        $this->line('Verified procedures: '.count($procedures));
        $this->line('Item prefix lookup rows: '.count($items));
        $this->line('Monster prefix lookup rows: '.count($monsters));
        $this->line('Character rows: '.(int) $pdo->query('SELECT COUNT_BIG(*) FROM [dbo].[_Char] WHERE [Deleted] = 0')->fetchColumn());

        if ($missingColumns !== []) {
            $this->error('Missing _Char columns: '.implode(', ', $missingColumns));
            return self::FAILURE;
        }
        if (collect($actions)->contains(fn (array $action): bool => ! $action['available'])) {
            $this->error('One or more requested actions are not available.');
            return self::FAILURE;
        }
        foreach (['Add silk', 'Change level', 'Spawn unique', 'Item to online'] as $label) {
            if (! str_contains($rendered, $label)) {
                $this->error('The rendered Player Control page is missing: '.$label);
                return self::FAILURE;
            }
        }

        $this->info('Player Control contract and rendered page are ready. No write command was executed.');
        return self::SUCCESS;
    }
}
