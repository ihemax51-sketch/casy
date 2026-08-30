<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveServerProfileRequest;
use App\Models\ServerProfile;
use App\Services\OperationService;
use App\Services\SchemaDiscoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ServerProfileController extends Controller
{
    public function index(): View
    {
        return view('settings.server', [
            'profile' => auth()->user()->serverProfiles()->where('is_active', true)->latest()->first(),
            'defaults' => config('casy.defaults', []),
        ]);
    }

    public function store(SaveServerProfileRequest $request): RedirectResponse
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->first();
        $data = $request->validated();
        $defaults = config('casy.defaults', []);
        if (! $profile) {
            foreach (['name', 'host', 'port', 'username', 'account_database', 'shard_database', 'log_database', 'proxy_database', 'icon_root'] as $key) {
                if (($data[$key] ?? null) === null || $data[$key] === '') {
                    if (($defaults[$key] ?? null) !== null && $defaults[$key] !== '') {
                        $data[$key] = $defaults[$key];
                    }
                }
            }
        }
        $data['encrypt_connection'] = $request->boolean('encrypt_connection');
        $data['trust_server_certificate'] = $request->boolean('trust_server_certificate');

        if (! $profile && empty($data['password'])) {
            $data['password'] = $defaults['password'] ?? null;
        }
        if (! $profile && empty($data['password'])) {
            return back()->withErrors(['password' => 'A password is required for the first connection.'])->withInput();
        }
        if ($profile && empty($data['password'])) {
            unset($data['password']);
        }

        $profile ??= new ServerProfile(['user_id' => auth()->id(), 'is_active' => true]);
        $profile->fill($data);
        $profile->connection_status = 'not_tested';
        $profile->connection_error = null;
        $profile->save();

        $this->log($profile, 'server_profile.saved', ['name' => $profile->name, 'host' => $profile->host]);

        return back()->with('success', 'Server profile saved. Test the connection to continue.');
    }

    public function test(Request $request, SchemaDiscoveryService $discovery): RedirectResponse
    {
        $profile = $this->profile();
        try {
            $result = $discovery->inspect($profile);
            $this->fillSuggestedDatabases($profile, $result['available_databases']);
            if ($profile->isDirty(['account_database', 'shard_database', 'log_database', 'proxy_database'])) {
                $profile->save();
                $result = $discovery->inspect($profile);
            }
            $profile->forceFill([
                'connection_status' => 'connected',
                'connection_error' => null,
                'schema_stats' => $result,
                'schema_fingerprint' => $result['fingerprint'],
                'last_tested_at' => now(),
                'last_connected_at' => now(),
            ])->save();
            $this->log($profile, 'server_profile.tested', ['status' => 'connected', 'server' => $result['server']]);

            return back()->with('success', 'SQL Server connected and the available databases were inspected.');
        } catch (Throwable $exception) {
            $profile->forceFill([
                'connection_status' => 'failed',
                'connection_error' => $exception->getMessage(),
                'last_tested_at' => now(),
            ])->save();
            $this->log($profile, 'server_profile.tested', ['status' => 'failed']);

            return back()->withErrors(['connection' => $exception->getMessage()]);
        }
    }

    public function discover(SchemaDiscoveryService $discovery): RedirectResponse
    {
        return $this->test(request(), $discovery);
    }

    private function profile(): ServerProfile
    {
        return auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
    }

    private function fillSuggestedDatabases(ServerProfile $profile, array $databases): void
    {
        $find = static function (string $pattern) use ($databases): ?string {
            foreach ($databases as $database) {
                if (preg_match($pattern, $database)) {
                    return $database;
                }
            }
            return null;
        };

        $profile->account_database ??= $find('/ACCOUNT/i');
        $profile->log_database ??= $find('/SHARDLOG|_LOG$/i');
        $profile->shard_database ??= $find('/SHARD(?!.*LOG)(?!.*INIT)/i');
        $profile->proxy_database ??= $find('/KMT|GUARD|FILTER/i');
    }

    private function log(ServerProfile $profile, string $action, array $summary): void
    {
        try {
            $operation = app(OperationService::class)->begin($action, auth()->id(), $profile->id, ['execution_mode' => 'configuration']);
            app(OperationService::class)->complete($operation, [], $summary);
            $operation->forceFill(['entity_type' => 'server_profile', 'entity_key' => (string) $profile->id])->save();
        } catch (Throwable) {
            // Connection settings remain authoritative if local audit storage is unavailable.
        }
    }
}
