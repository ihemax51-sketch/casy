<?php

namespace App\Http\Controllers;

use App\Services\OperationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

final class MaintenanceController extends Controller
{
    public function index(): View
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->first();
        return view('studio.maintenance.index', ['profile' => $profile, 'actions' => (array) config('casy.emergency_actions', []), 'preview' => session('maintenance_preview')]);
    }

    public function dryRun(Request $request): RedirectResponse
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
        if ($profile->connection_status !== 'connected') {
            return back()->withErrors(['maintenance' => 'Connect the active server before running a maintenance dry-run.']);
        }
        $data = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', config('casy.emergency_actions', []))],
            'database_name' => ['required', 'string', 'max:128'], 'backup_reference' => ['required', 'string', 'max:255'],
            'backup_at' => ['required', 'date'], 'password' => ['required', 'string'],
        ]);
        if (! Hash::check($data['password'], (string) auth()->user()->password)) {
            return back()->withErrors(['password' => 'Owner password confirmation failed.'])->withInput($request->except('password'));
        }
        if ((string) $data['database_name'] !== (string) $profile->shard_database) {
            return back()->withErrors(['database_name' => 'Type the complete active shard database name to continue.'])->withInput();
        }
        if (now()->diffInHours($data['backup_at']) > 24 || now()->lt($data['backup_at'])) {
            return back()->withErrors(['backup_at' => 'Backup proof must be from the last 24 hours.'])->withInput();
        }
        $preview = ['action' => $data['action'], 'database' => $data['database_name'], 'backup_reference' => $data['backup_reference'], 'backup_at' => $data['backup_at'], 'status' => 'dry-run only', 'message' => 'No rows were changed. A reviewed adapter is required before an emergency operation can execute.'];
        $operation = app(OperationService::class)->begin('maintenance.dry-run', auth()->id(), $profile->id, ['execution_mode' => 'dry-run', 'action' => $data['action'], 'database_name' => $data['database_name'], 'backup_reference' => $data['backup_reference'], 'backup_at' => $data['backup_at']], 'critical');
        app(OperationService::class)->complete($operation, $preview);
        return back()->with('maintenance_preview', $preview)->with('success', 'Dry-run completed. No database rows were changed.');
    }
}
