<?php

namespace App\Http\Controllers;

use App\Models\IconAsset;
use App\Services\DdjThumbnailService;
use App\Services\IconLibraryService;
use App\Services\OperationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

class IconLibraryController extends Controller
{
    public function index(Request $request): View
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->first();
        $search = trim((string) $request->query('q', ''));
        $icons = IconAsset::query()
            ->when($profile, fn ($query) => $query->where('server_profile_id', $profile->id))
            ->when($search !== '', fn ($query) => $query->where('relative_path', 'like', '%'.$search.'%'))
            ->orderBy('relative_path')
            ->paginate(80)
            ->withQueryString();

        return view('studio.icons.index', compact('profile', 'icons', 'search'));
    }

    public function scan(IconLibraryService $library): RedirectResponse
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
        try {
            $result = $library->scan($profile);
            $operation = app(OperationService::class)->begin('icon_library.scanned', auth()->id(), $profile->id, ['execution_mode' => 'configuration']);
            app(OperationService::class)->complete($operation, [], $result);
            $operation->forceFill(['entity_type' => 'icon_library', 'entity_key' => (string) $profile->id])->save();

            $removed = (int) ($result['removed'] ?? 0);
            return back()->with('success', number_format($result['scanned']).' DDJ icons indexed successfully'.($removed ? '; '.number_format($removed).' missing assets removed.' : '.'));
        } catch (Throwable $exception) {
            return back()->withErrors(['icons' => $exception->getMessage()]);
        }
    }

    public function thumbnail(IconAsset $iconAsset, DdjThumbnailService $thumbnails): Response
    {
        abort_unless($iconAsset->serverProfile->user_id === auth()->id(), 403);
        try {
            return response()->file($thumbnails->render($iconAsset), [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, max-age=86400',
            ]);
        } catch (Throwable) {
            $iconAsset->forceFill(['thumbnail_status' => 'failed'])->save();
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#edf2f7"/><path d="M19 42l9-10 7 7 5-6 7 9" fill="none" stroke="#9aa9bc" stroke-width="3"/><circle cx="25" cy="24" r="4" fill="#9aa9bc"/></svg>';

            return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=300']);
        }
    }
}
