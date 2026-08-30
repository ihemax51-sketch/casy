<?php

namespace App\Http\Controllers;

use App\Models\IconAsset;
use App\Services\OperationService;
use App\Services\SroCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ItemController extends Controller
{
    public function lookup(Request $request, SroCatalogService $catalog): JsonResponse
    {
        $profile = $this->profile();
        $search = trim((string) $request->query('q', ''));
        if (mb_strlen($search) > 128) {
            return response()->json(['items' => []], 422);
        }

        $items = $catalog->items($profile, $search, 30);
        $icons = $this->iconsFor($profile->id, $items);

        return response()->json(['items' => array_map(function (array $item) use ($icons): array {
            $path = str_replace('/', '\\', ltrim((string) ($item['AssocFileIcon128'] ?? ''), '\\/'));
            $icon = $path !== '' ? $icons->get(hash('sha256', strtolower($path))) : null;

            return [
                'id' => (int) $item['ID'],
                'code' => (string) $item['CodeName128'],
                'name' => (string) ($item['NameStrID128'] ?? $item['CodeName128']),
                'icon_url' => $icon ? route('icons.thumbnail', $icon) : null,
            ];
        }, $items)]);
    }

    public function monsters(Request $request, SroCatalogService $catalog): JsonResponse
    {
        $profile = $this->profile();
        $search = trim((string) $request->query('q', ''));
        if ($search === '' || mb_strlen($search) > 128) {
            return response()->json(['monsters' => []]);
        }

        return response()->json(['monsters' => array_map(static fn (array $monster): array => [
            'id' => (int) $monster['ID'],
            'code' => (string) $monster['CodeName128'],
            'name' => (string) ($monster['NameStrID128'] ?? $monster['CodeName128']),
            'rarity' => (int) ($monster['Rarity'] ?? 0),
        ], $catalog->monsters($profile, $search))]);
    }

    public function index(Request $request, SroCatalogService $catalog): View
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->first();
        $search = trim((string) $request->query('q', ''));
        $page = max(1, min((int) $request->query('page', 1), 10000));
        $perPage = 48;
        $items = [];
        $total = 0;
        $error = null;

        if ($profile && $profile->connection_status === 'connected') {
            try {
                $total = $catalog->itemCount($profile, $search);
                $items = $catalog->items($profile, $search, $perPage, ($page - 1) * $perPage);
                $hashes = [];
                foreach ($items as $item) {
                    if (! empty($item['AssocFileIcon128'])) {
                        $path = str_replace('/', '\\', ltrim($item['AssocFileIcon128'], '\\/'));
                        $hashes[] = hash('sha256', strtolower($path));
                    }
                }
                $icons = IconAsset::where('server_profile_id', $profile->id)->whereIn('path_hash', $hashes)->get()->keyBy('path_hash');
                foreach ($items as &$item) {
                    $path = str_replace('/', '\\', ltrim($item['AssocFileIcon128'] ?? '', '\\/'));
                    $item['_icon'] = $path ? $icons->get(hash('sha256', strtolower($path))) : null;
                }
                unset($item);
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return view('studio.items.index', compact('profile', 'items', 'search', 'error', 'page', 'perPage', 'total'));
    }

    public function show(Request $request, int $item, SroCatalogService $catalog): View
    {
        $profile = $this->profile();
        $record = null;
        $error = null;
        try {
            $record = $catalog->item($profile, $item);
            if (! $record) {
                abort(404, 'Item was not found.');
            }
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            $error = $exception->getMessage();
        }

        $icon = null;
        if ($record && ! empty($record['row']['AssocFileIcon128'])) {
            $path = str_replace('/', '\\', ltrim((string) $record['row']['AssocFileIcon128'], '\\/'));
            $icon = IconAsset::where('server_profile_id', $profile->id)->where('path_hash', hash('sha256', strtolower($path)))->first();
        }

        $returnTo = $this->npcReturnUrl($request);

        return view('studio.items.edit', compact('profile', 'record', 'error', 'icon', 'returnTo'));
    }

    public function create(Request $request, SroCatalogService $catalog): View
    {
        $profile = $this->profile();
        $templates = $catalog->items($profile, trim((string) $request->query('q', '')), 40);
        $templateId = $request->filled('template') ? (int) $request->query('template') : null;
        $template = $templateId ? $catalog->item($profile, $templateId) : null;

        return view('studio.items.create', compact('profile', 'templates', 'template', 'templateId'));
    }

    public function store(Request $request, SroCatalogService $catalog): RedirectResponse
    {
        $profile = $this->profile();
        $data = $request->validate([
            'template_id' => ['required', 'integer', 'min:1'],
            'code_name' => ['required', 'string', 'max:128'],
            'name_key' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $changes = ['CodeName128' => $data['code_name']];
            if (($data['name_key'] ?? '') !== '') {
                $changes['NameStrID128'] = $data['name_key'];
            }
            $result = $catalog->cloneItem($profile, (int) $data['template_id'], $changes);
            $this->audit($profile, 'item.created-from-template', (string) $result['id'], ['source_id' => (int) $data['template_id'], 'new_id' => $result['id']], $result['sql']);

            return redirect()->route('items.edit', $result['id'])->with('success', 'Item created from the selected template.');
        } catch (Throwable $exception) {
            return back()->withErrors(['item' => $exception->getMessage()])->withInput();
        }
    }

    public function update(Request $request, int $item, SroCatalogService $catalog): RedirectResponse
    {
        $profile = $this->profile();
        try {
            $result = $catalog->updateItem($profile, $item, $this->fields($request, 'fields'), $this->fields($request, 'details'));
            $this->audit($profile, 'item.updated', (string) $item, ['row_count' => $result['row_count']], $result['sql']);
            return redirect()->to($this->itemRedirect($request, $item))->with('success', 'Item updated and the change was added to the audit trail.');
        } catch (Throwable $exception) {
            return back()->withErrors(['item' => $exception->getMessage()])->withInput();
        }
    }

    public function clone(Request $request, int $item, SroCatalogService $catalog): RedirectResponse
    {
        $profile = $this->profile();
        try {
            $result = $catalog->cloneItem($profile, $item, $this->fields($request, 'fields'));
            $this->audit($profile, 'item.cloned', (string) $result['id'], ['source_id' => $item, 'new_id' => $result['id']], $result['sql']);
            return redirect()->to($this->itemRedirect($request, $result['id']))->with('success', 'Item cloned successfully.');
        } catch (Throwable $exception) {
            return back()->withErrors(['item' => $exception->getMessage()])->withInput();
        }
    }

    private function profile()
    {
        return auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
    }

    private function iconsFor(int $profileId, array $items)
    {
        $hashes = [];
        foreach ($items as $item) {
            $path = str_replace('/', '\\', ltrim((string) ($item['AssocFileIcon128'] ?? ''), '\\/'));
            if ($path !== '') {
                $hashes[] = hash('sha256', strtolower($path));
            }
        }

        return IconAsset::where('server_profile_id', $profileId)->whereIn('path_hash', array_unique($hashes))->get()->keyBy('path_hash');
    }

    private function fields(Request $request, string $input): array
    {
        $fields = [];
        foreach ((array) $request->input($input, []) as $column => $value) {
            if (is_string($column) && preg_match('/^[A-Za-z0-9_$-]+$/', $column) && is_scalar($value)) {
                $fields[$column] = (string) $value;
            }
        }
        return $fields;
    }

    private function npcReturnUrl(Request $request): ?string
    {
        if ($request->input('from') !== 'npc-shops') {
            return null;
        }
        $npc = (string) $request->query('npc', '');
        $tab = (string) $request->query('tab', '');
        if ($npc === '' || ! preg_match('/^[A-Za-z0-9_\\-]+$/', $npc) || ($tab !== '' && ! preg_match('/^[A-Za-z0-9_\\-]+$/', $tab))) {
            return null;
        }

        return route('npc-shops.index', array_filter(['npc' => $npc, 'tab' => $tab]));
    }

    private function itemRedirect(Request $request, int $item): string
    {
        $returnTo = $this->npcReturnUrl($request);
        if (! $returnTo) {
            return route('items.edit', $item);
        }

        return route('items.edit', array_filter(['item' => $item, 'from' => 'npc-shops', 'npc' => $request->input('npc', $request->query('npc')), 'tab' => $request->input('tab', $request->query('tab'))]));
    }

    private function audit($profile, string $action, string $key, array $summary, ?string $sql = null): void
    {
        try {
            $operation = app(OperationService::class)->begin($action, auth()->id(), $profile->id, ['execution_mode' => 'live', 'entity' => '_RefObjCommon', 'key' => $key], 'high');
            app(OperationService::class)->complete($operation, [], $summary);
            $operation->forceFill(['entity_type' => '_RefObjCommon', 'entity_key' => $key, 'generated_sql' => $sql])->save();
        } catch (Throwable) {
            // A committed catalog transaction remains successful if the local audit store is unavailable.
        }
    }
}
