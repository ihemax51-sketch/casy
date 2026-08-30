<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\IconLibraryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\NpcShopController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ServerProfileController;
use App\Http\Controllers\SchemaController;
use App\Http\Controllers\TableStudioController;
use App\Http\Controllers\AreaStudioController;
use App\Http\Controllers\StudioModuleController;
use App\Http\Controllers\LiveCommandController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\GameAutomationController;
use App\Http\Controllers\AutomationLibraryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('live.index') : view('auth.login');
});

Route::get('/dashboard', fn () => redirect()->route('live.index'))->middleware(['auth', 'owner.active'])->name('dashboard');

Route::middleware(['auth', 'owner.active'])->group(function () {
    Route::get('/automations', AutomationLibraryController::class)->name('automations.index');
    Route::get('/items', [ItemController::class, 'index'])->name('items.index');
    Route::get('/lookups/items', [ItemController::class, 'lookup'])->middleware('throttle:120,1')->name('lookups.items');
    Route::get('/lookups/monsters', [ItemController::class, 'monsters'])->middleware('throttle:120,1')->name('lookups.monsters');
    Route::get('/items/create', [ItemController::class, 'create'])->name('items.create');
    Route::post('/items/create', [ItemController::class, 'store'])->name('items.store');
    Route::get('/items/{item}', [ItemController::class, 'show'])->whereNumber('item')->name('items.edit');
    Route::put('/items/{item}', [ItemController::class, 'update'])->whereNumber('item')->name('items.update');
    Route::post('/items/{item}/clone', [ItemController::class, 'clone'])->whereNumber('item')->name('items.clone');
    Route::get('/npc-shops', [NpcShopController::class, 'index'])->name('npc-shops.index');
    Route::put('/npc-shops/prices/{price}', [NpcShopController::class, 'updatePrice'])->whereNumber('price')->name('npc-shops.price.update');
    Route::delete('/npc-shops/goods/{goods}', [NpcShopController::class, 'removeGood'])->whereNumber('goods')->name('npc-shops.goods.destroy');
    Route::post('/npc-shops/tabs', [NpcShopController::class, 'createTab'])->name('npc-shops.tabs.store');
    Route::get('/regions', [AreaStudioController::class, 'index'])->name('regions.index');
    Route::post('/regions/preview', [AreaStudioController::class, 'preview'])->name('regions.preview');
    Route::post('/regions/create', [AreaStudioController::class, 'store'])->name('regions.store');
    Route::put('/regions/{world}', [AreaStudioController::class, 'update'])->whereNumber('world')->name('regions.update');
    Route::post('/regions/{world}/mappings', [AreaStudioController::class, 'addMappings'])->whereNumber('world')->name('regions.mappings.store');
    Route::delete('/regions/{world}/mappings/{region}', [AreaStudioController::class, 'removeMapping'])->whereNumber(['world', 'region'])->name('regions.mappings.destroy');
    Route::get('/icon-library', [IconLibraryController::class, 'index'])->name('icons.index');
    Route::post('/icon-library/scan', [IconLibraryController::class, 'scan'])->name('icons.scan');
    Route::get('/icon-library/{iconAsset}/thumbnail', [IconLibraryController::class, 'thumbnail'])->name('icons.thumbnail');
    Route::get('/logs', [AuditLogController::class, 'index'])->name('logs.index');
    Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('/maintenance/dry-run', [MaintenanceController::class, 'dryRun'])->middleware('throttle:10,1')->name('maintenance.dry-run');
    Route::get('/live', [LiveCommandController::class, 'index'])->name('live.index');
    Route::get('/live/player', [LiveCommandController::class, 'player'])->middleware('throttle:120,1')->name('live.player');
    Route::post('/live/execute', [LiveCommandController::class, 'execute'])->middleware('throttle:30,1')->name('live.execute');
    Route::get('/schema', [SchemaController::class, 'index'])->name('schema.index');
    Route::get('/data/{database}/{table}', [TableStudioController::class, 'index'])->where(['database' => '[A-Za-z0-9_$-]+', 'table' => '[A-Za-z0-9_$-]+'])->name('data.index');
    Route::put('/data/{database}/{table}', [TableStudioController::class, 'update'])->where(['database' => '[A-Za-z0-9_$-]+', 'table' => '[A-Za-z0-9_$-]+'])->name('data.update');
    Route::post('/data/{database}/{table}', [TableStudioController::class, 'insert'])->where(['database' => '[A-Za-z0-9_$-]+', 'table' => '[A-Za-z0-9_$-]+'])->name('data.insert');

    Route::get('/settings/server', [ServerProfileController::class, 'index'])->name('server-profile.index');
    Route::post('/settings/server', [ServerProfileController::class, 'store'])->name('server-profile.store');
    Route::post('/settings/server/test', [ServerProfileController::class, 'test'])->name('server-profile.test');
    Route::post('/settings/server/discover', [ServerProfileController::class, 'discover'])->name('server-profile.discover');

    Route::get('/studio/{module}', StudioModuleController::class)->where('module', 'accounts|characters|inventory|skills|silk|monsters|drops|fortress|economy')->name('studio.module');
    Route::post('/studio/{module}/actions/{action}', [GameAutomationController::class, 'run'])
        ->where(['module' => 'accounts|characters|inventory|skills|silk|monsters|drops|fortress|economy', 'action' => '[a-z0-9.-]+'])
        ->middleware('throttle:20,1')
        ->name('studio.action.run');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
