<?php

namespace App\Http\Controllers;

use App\Services\AreaStudioService;
use App\Services\OperationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AreaStudioController extends Controller
{
    public function index(Request $request, AreaStudioService $areas): View
    {
        $sourceWorldId = $request->filled('source') ? (int) $request->query('source') : null;

        return $this->screen($request, $areas, $sourceWorldId);
    }

    public function preview(Request $request, AreaStudioService $areas): View|RedirectResponse
    {
        $input = $this->validatedInput($request);
        try {
            return $this->screen($request, $areas, $input['source_world_id'], $input, $areas->previewSql($input));
        } catch (Throwable $exception) {
            return back()->withErrors(['area' => $exception->getMessage()])->withInput();
        }
    }

    public function store(Request $request, AreaStudioService $areas): RedirectResponse
    {
        $profile = $this->profile();
        $input = $this->validatedInput($request);

        try {
            $result = $areas->createInstanceWorld($profile, $input);
        } catch (Throwable $exception) {
            return back()->withErrors(['area' => $exception->getMessage()])->withInput();
        }

        $this->audit($profile, 'instance-world.created', '_RefGame_World', (string) $result['world_id'], [
            'world_code' => $result['world_code'], 'region_count' => $result['region_count'], 'start_count' => $result['start_count'], 'source_world_id' => $input['source_world_id'], 'dependencies' => $result['dependencies'] ?? [],
        ], $result['sql'], 'high');

        $dependencies = $result['dependencies'] ?? [];
        $dependencyMessage = ($dependencies['mode'] ?? 'quick') === 'full'
            ? " Full clone also copied {$dependencies['teleports']} teleport(s), {$dependencies['tele_links']} link(s), {$dependencies['hives']} hive(s) and {$dependencies['nests']} nest(s)."
            : '';
        return redirect()->route('regions.index', ['source' => $result['world_id']])
            ->with('success', "Instance world {$result['world_code']} was created with {$result['region_count']} terrain region(s) and {$result['start_count']} start position(s).{$dependencyMessage}");
    }

    public function update(Request $request, int $world, AreaStudioService $areas): RedirectResponse
    {
        $profile = $this->profile();
        $input = $this->validatedWorldInput($request);
        try {
            $result = $areas->updateInstanceWorld($profile, $world, $input);
            $this->audit($profile, 'instance-world.updated', '_RefGame_World', (string) $world, ['world_code' => $result['world_code']], null, 'medium');

            return redirect()->route('regions.index', ['source' => $world])->with('success', "World {$result['world_code']} was updated.");
        } catch (Throwable $exception) {
            return back()->withErrors(['area' => $exception->getMessage()])->withInput();
        }
    }

    public function addMappings(Request $request, int $world, AreaStudioService $areas): RedirectResponse
    {
        $profile = $this->profile();
        $input = $request->validate(['region_ids' => ['required', 'string', 'max:100000']]);
        try {
            $result = $areas->addRegionMappings($profile, $world, $input['region_ids']);
            $this->audit($profile, 'instance-world.regions-added', '_RefInstance_World_Region', (string) $world, $result, null, 'medium');

            return redirect()->route('regions.index', ['source' => $world])->with('success', "{$result['added']} new region mapping(s) added.");
        } catch (Throwable $exception) {
            return back()->withErrors(['area' => $exception->getMessage()])->withInput();
        }
    }

    public function removeMapping(Request $request, int $world, int $region, AreaStudioService $areas): RedirectResponse
    {
        $profile = $this->profile();
        try {
            $result = $areas->removeRegionMapping($profile, $world, $region);
            $this->audit($profile, 'instance-world.region-removed', '_RefInstance_World_Region', $world.':'.$region, $result, null, 'high');

            return redirect()->route('regions.index', ['source' => $world])->with('success', "Region {$region} was removed from the world.");
        } catch (Throwable $exception) {
            return back()->withErrors(['area' => $exception->getMessage()])->withInput();
        }
    }

    private function screen(Request $request, AreaStudioService $areas, ?int $sourceWorldId, ?array $form = null, ?string $preview = null): View
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->first();
        $workspace = ['worlds' => [], 'world' => null, 'source_regions' => [], 'start_positions' => [], 'regions' => [], 'total_region_count' => 0, 'next_world_id' => null];
        $error = null;
        $regionSearch = trim((string) $request->input('q', ''));

        if ($profile?->connection_status === 'connected') {
            try {
                $workspace = $areas->workspace($profile, $sourceWorldId, $regionSearch);
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        $form ??= $this->defaults($workspace);

        return view('studio.regions.index', compact('profile', 'workspace', 'error', 'regionSearch', 'form', 'preview'));
    }

    private function defaults(array $workspace): array
    {
        $world = $workspace['world'];

        return [
            'source_world_id' => $world ? (int) $world['ID'] : null,
            'new_world_id' => $workspace['next_world_id'],
            'world_code' => $world ? $world['WorldCodeName128'].'_COPY' : 'INS_NEW_AREA',
            'type' => $world['Type'] ?? 0,
            'max_count' => $world['WorldMaxCount'] ?? 1,
            'max_users' => $world['WorldMaxUserCount'] ?? 0,
            'entry_type' => $world['WorldEntryType'] ?? 0,
            'entrance_type' => $world['WorldEntranceType'] ?? 0,
            'leave_type' => $world['WorldLeaveType'] ?? 0,
            'duration_time' => $world['WorldDurationTime'] ?? 0,
            'empty_remain_time' => $world['WorldEmptyRemainTime'] ?? 0,
            'config_group' => $world['ConfigGroupCodeName128'] ?? 'xxx',
            'region_ids' => implode(', ', array_column($workspace['source_regions'], 'region_id')),
            'clone_start_positions' => false,
            'clone_mode' => 'quick',
        ];
    }

    private function validatedInput(Request $request): array
    {
        $input = $request->validate([
            'source_world_id' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'new_world_id' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'world_code' => ['required', 'string', 'max:128'],
            'type' => ['required', 'integer', 'min:0', 'max:255'],
            'max_count' => ['required', 'integer', 'min:0', 'max:32767'],
            'max_users' => ['required', 'integer', 'min:0', 'max:32767'],
            'entry_type' => ['required', 'integer', 'min:0', 'max:255'],
            'entrance_type' => ['required', 'integer', 'min:0', 'max:255'],
            'leave_type' => ['required', 'integer', 'min:0', 'max:255'],
            'duration_time' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'empty_remain_time' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'config_group' => ['required', 'string', 'max:128'],
            'region_ids' => ['required', 'string', 'max:100000'],
            'clone_start_positions' => ['nullable', 'boolean'],
            'clone_mode' => ['nullable', 'in:quick,full'],
        ]);
        $input['clone_start_positions'] = $request->boolean('clone_start_positions');
        $input['clone_mode'] = $request->input('clone_mode', 'quick');
        if ($input['clone_mode'] === 'full' && empty($input['source_world_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['clone_mode' => 'Full clone requires a source world. Choose a template or use Quick clone.']);
        }

        return $input;
    }

    private function validatedWorldInput(Request $request): array
    {
        return $request->validate([
            'world_code' => ['required', 'string', 'max:128'],
            'type' => ['required', 'integer', 'min:0', 'max:255'],
            'max_count' => ['required', 'integer', 'min:0', 'max:32767'],
            'max_users' => ['required', 'integer', 'min:0', 'max:32767'],
            'entry_type' => ['required', 'integer', 'min:0', 'max:255'],
            'entrance_type' => ['required', 'integer', 'min:0', 'max:255'],
            'leave_type' => ['required', 'integer', 'min:0', 'max:255'],
            'duration_time' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'empty_remain_time' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'config_group' => ['required', 'string', 'max:128'],
        ]);
    }

    private function profile()
    {
        return auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
    }

    private function audit($profile, string $action, string $entity, string $key, array $summary, ?string $sql, string $risk): void
    {
        try {
            $operation = app(OperationService::class)->begin($action, auth()->id(), $profile->id, ['execution_mode' => 'live', 'entity' => $entity, 'key' => $key], $risk);
            app(OperationService::class)->complete($operation, [], $summary);
            $operation->forceFill(['entity_type' => $entity, 'entity_key' => $key, 'generated_sql' => $sql])->save();
        } catch (Throwable) {
            // The committed SQL transaction remains authoritative if local audit storage is unavailable.
        }
    }
}
