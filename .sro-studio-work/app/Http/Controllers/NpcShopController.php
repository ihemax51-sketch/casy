<?php

namespace App\Http\Controllers;

use App\Models\IconAsset;
use App\Services\NpcShopCatalogService;
use App\Services\OperationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class NpcShopController extends Controller
{
    public function index(Request $request, NpcShopCatalogService $shops): View
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->first();
        $workspace = ['npcs' => [], 'npc' => null, 'groups' => [], 'tabs' => [], 'tab' => null, 'items' => []];
        $error = null;
        $search = trim((string) $request->query('q', ''));
        if ($profile?->connection_status === 'connected') {
            try {
                $workspace = $shops->workspace($profile, $search, $request->query('npc'), $request->query('tab'));
                $hashes = [];
                foreach ($workspace['items'] as $item) {
                    if ($item['icon_path'] ?? null) {
                        $path = str_replace('/', '\\', ltrim($item['icon_path'], '\\/'));
                        $hashes[] = hash('sha256', strtolower($path));
                    }
                }
                $icons = IconAsset::where('server_profile_id', $profile->id)->whereIn('path_hash', $hashes)->get()->keyBy('path_hash');
                foreach ($workspace['items'] as &$item) {
                    $path = str_replace('/', '\\', ltrim($item['icon_path'] ?? '', '\\/'));
                    $item['_icon'] = $path ? $icons->get(hash('sha256', strtolower($path))) : null;
                }
                unset($item);
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return view('studio.npc-shops.index', compact('profile', 'workspace', 'error', 'search'));
    }

    public function updatePrice(Request $request, int $price, NpcShopCatalogService $shops): RedirectResponse
    {
        $profile = $this->profile();
        $data = $request->validate(['cost' => ['required', 'integer', 'min:0', 'max:2147483647']]);
        try {
            $result = $shops->updatePrice($profile, $price, (int) $data['cost']);
            $this->audit($profile, 'npc-shop.price-updated', '_RefPricePolicyOfItem', (string) $price, $result);

            return $this->backToShop($request)->with('success', 'The NPC item price was updated.');
        } catch (Throwable $exception) {
            return $this->backToShop($request)->withErrors(['npc' => $exception->getMessage()]);
        }
    }

    public function removeGood(Request $request, int $goods, NpcShopCatalogService $shops): RedirectResponse
    {
        $profile = $this->profile();
        try {
            $result = $shops->removeGood($profile, $goods);
            $this->audit($profile, 'npc-shop.good-removed', '_RefShopGoods', (string) $goods, $result);

            return $this->backToShop($request)->with('success', 'The item was removed from this NPC tab.');
        } catch (Throwable $exception) {
            return $this->backToShop($request)->withErrors(['npc' => $exception->getMessage()]);
        }
    }

    public function createTab(Request $request, NpcShopCatalogService $shops): RedirectResponse
    {
        $profile = $this->profile();
        $data = $request->validate([
            'shop_code' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'tab_group_code' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'tab_group_name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'tab_code' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'tab_name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ]);
        try {
            $result = $shops->createTab($profile, $data);
            $this->audit($profile, 'npc-shop.tab-created', '_RefShopTab', $result['tab_code'], $result);

            return $this->backToShop($request)->with('success', "Tab {$result['tab_code']} was created and linked to the shop.");
        } catch (Throwable $exception) {
            return $this->backToShop($request)->withErrors(['npc' => $exception->getMessage()])->withInput();
        }
    }

    private function profile()
    {
        return auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
    }

    private function backToShop(Request $request): RedirectResponse
    {
        $npc = (string) $request->input('npc', '');
        $tab = (string) $request->input('tab', '');
        if ($npc !== '' && preg_match('/^[A-Za-z0-9_\-]+$/', $npc) && ($tab === '' || preg_match('/^[A-Za-z0-9_\-]+$/', $tab))) {
            return redirect()->route('npc-shops.index', array_filter(['npc' => $npc, 'tab' => $tab]));
        }

        return redirect()->route('npc-shops.index');
    }

    private function audit($profile, string $action, string $entity, string $key, array $summary): void
    {
        try {
            $operation = app(OperationService::class)->begin($action, auth()->id(), $profile->id, [
                'execution_mode' => 'live', 'entity' => $entity, 'key' => $key,
            ], in_array($action, ['npc-shop.good-removed', 'npc-shop.tab-created'], true) ? 'high' : 'medium');
            app(OperationService::class)->complete($operation, $summary, ['entity' => $entity, 'key' => $key]);
            $operation->forceFill(['entity_type' => $entity, 'entity_key' => $key])->save();
        } catch (Throwable) {
            // The SQL transaction is already committed; audit storage must not hide a successful change.
        }
    }
}
