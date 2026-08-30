<?php

namespace App\Http\Controllers;

use App\Services\OperationService;
use App\Services\PlayerControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class LiveCommandController extends Controller
{
    public function index(Request $request, PlayerControlService $control): View
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->first();
        $actions = [];
        $character = null;
        $error = null;

        if ($profile && $profile->connection_status === 'connected') {
            try {
                $actions = $control->actions($profile);
                if ($request->filled('target_value')) {
                    $character = $control->character(
                        $profile,
                        (string) $request->query('target_mode', 'id'),
                        (string) $request->query('target_value'),
                    );
                }
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return view('studio.live.index', compact('profile', 'actions', 'character', 'error'));
    }

    public function player(Request $request, PlayerControlService $control): JsonResponse
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['id', 'name'])],
            'value' => ['required', 'string', 'max:64'],
        ]);

        try {
            return response()->json([
                'ok' => true,
                'character' => $control->character($profile, $validated['mode'], $validated['value']),
            ]);
        } catch (Throwable $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function execute(Request $request, PlayerControlService $control): RedirectResponse
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
        $validated = $request->validate([
            'action' => ['required', Rule::in([
                'silk', 'level', 'gold', 'stats', 'skill_points', 'position', 'town', 'refresh',
                'player_notice', 'server_notice', 'disconnect', 'spawn', 'item_player', 'item_online',
            ])],
            'target_mode' => ['nullable', Rule::in(['id', 'name'])],
            'target_value' => ['nullable', 'string', 'max:64'],
        ]);
        $action = $validated['action'];

        $input = match ($action) {
            'silk' => $request->validate([
                'silk_type' => ['required', Rule::in(['normal', 'gift', 'point'])],
                'amount' => ['required', 'integer', 'between:1,2000000000'],
            ]),
            'level' => $request->validate(['level' => ['required', 'integer', 'between:1,255']]),
            'gold', 'skill_points' => $request->validate(['amount' => ['required', 'integer', 'between:1,2000000000']]),
            'stats' => $request->validate([
                'stat_type' => ['required', Rule::in(['strength', 'intellect'])],
                'amount' => ['required', 'integer', 'between:1,32747'],
            ]),
            'position' => $this->positionInput($request),
            'player_notice', 'server_notice' => $request->validate([
                'notice_type' => ['required', 'integer', Rule::in(range(1, 10))],
                'notice' => ['required', 'string', 'max:500'],
            ]),
            'spawn' => $this->spawnInput($request),
            'item_player', 'item_online' => $request->validate([
                'item_code' => ['required', 'string', 'max:128'],
                'quantity' => ['required', 'integer', 'between:1,1000000'],
                'plus' => ['required', 'integer', 'between:0,255'],
            ]),
            default => [],
        };

        if ($control->requiresTarget($action, $input)) {
            $target = $request->validate([
                'target_mode' => ['required', Rule::in(['id', 'name'])],
                'target_value' => ['required', 'string', 'max:64'],
            ]);
            $validated = array_merge($validated, $target);
        }

        $operation = app(OperationService::class)->begin(
            'player.control',
            auth()->id(),
            $profile->id,
            [
                'action_name' => $action,
                'target_mode' => $validated['target_mode'] ?? null,
                'target_value' => $validated['target_value'] ?? null,
                'input' => $input,
                'execution_mode' => 'guided',
            ],
            in_array($action, ['disconnect', 'spawn', 'item_online'], true) ? 'high' : 'medium',
        );

        try {
            $result = $control->execute(
                $profile,
                $action,
                (string) ($validated['target_mode'] ?? 'id'),
                trim((string) ($validated['target_value'] ?? '')),
                $input,
            );
            app(OperationService::class)->complete($operation, $result['after'] ?? [], [
                'before' => $result['before'] ?? [],
                'procedure' => $result['procedure'] ?? null,
                'character' => $result['character'] ?? null,
                'warning' => $result['warning'] ?? null,
            ]);
            $operation->forceFill([
                'entity_type' => isset($result['character']) ? 'SRO_VT_SHARD.dbo._Char' : 'KMTGuard',
                'entity_key' => (string) data_get($result, 'character.id', $action),
                'generated_sql' => $result['generated_sql'] ?? null,
                'status' => 'success',
            ])->save();

            $routeParameters = ['action' => $action];
            if (($validated['target_value'] ?? '') !== '') {
                $routeParameters['target_mode'] = $validated['target_mode'];
                $routeParameters['target_value'] = $validated['target_value'];
            }

            $redirect = redirect()->route('live.index', $routeParameters)->with('success', $result['message']);
            if ($result['warning'] ?? null) {
                $redirect->with('warning', $result['warning']);
            }

            return $redirect;
        } catch (Throwable $exception) {
            app(OperationService::class)->fail($operation, $exception->getMessage());

            return back()->withErrors(['action' => $exception->getMessage()])->withInput();
        }
    }

    /** @return array<string, mixed> */
    private function positionInput(Request $request): array
    {
        return $request->validate([
            'world_id' => ['required', 'integer', 'between:1,65535'],
            'region_id' => ['required', 'integer', 'between:-32768,32767', 'not_in:0'],
            'pos_x' => ['required', 'integer', 'between:-1000000,1000000'],
            'pos_y' => ['required', 'integer', 'between:-1000000,1000000'],
            'pos_z' => ['required', 'integer', 'between:-1000000,1000000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function spawnInput(Request $request): array
    {
        $input = $request->validate([
            'spawn_mode' => ['required', Rule::in(['player', 'position'])],
            'monster_id' => ['required', 'integer', 'min:1'],
        ]);
        if ($input['spawn_mode'] === 'position') {
            $input = array_merge($input, $this->positionInput($request), $request->validate([
                'radius' => ['required', 'integer', 'between:0,1000000'],
            ]));
        }

        return $input;
    }
}
