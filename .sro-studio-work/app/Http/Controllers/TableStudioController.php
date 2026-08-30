<?php

namespace App\Http\Controllers;

use App\Services\OperationService;
use App\Services\SqlTableStudioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class TableStudioController extends Controller
{
    public function index(Request $request, string $database, string $table, SqlTableStudioService $studio): View
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
        $error = null;
        $data = null;
        try {
            $data = $studio->browse($profile, $database, $table, trim((string) $request->query('q', '')), $request->query('row'), (int) $request->query('page', 1), (int) config('casy.table_studio.page_size', 60));
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
        return view('studio.data.index', ['profile' => $profile, 'data' => $data, 'error' => $error, 'search' => (string) $request->query('q', '')]);
    }

    public function update(Request $request, string $database, string $table, SqlTableStudioService $studio): RedirectResponse
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
        $fields = [];
        foreach ((array) $request->input('fields', []) as $column => $value) {
            if (is_string($column) && preg_match('/^[A-Za-z0-9_$-]+$/', $column) && is_scalar($value)) {
                $fields[$column] = (string) $value;
            }
        }
        try {
            $key = (string) $request->input('key');
            $before = $studio->browse($profile, $database, $table, '', $key)['record'] ?? null;
            $operation = app(OperationService::class)->begin('table.row.updated', auth()->id(), $profile->id, [
                'execution_mode' => 'live', 'database' => $database, 'table' => $table, 'key' => $key, 'before' => $before,
            ], 'high');
            $result = $studio->update($profile, $database, $table, $key, $fields);
            $after = $studio->browse($profile, $database, $table, '', $key)['record'] ?? null;
            app(OperationService::class)->complete($operation, $after, ['row_count' => $result['row_count'], 'key_column' => $result['key_column'], 'sql' => $result['sql']]);
            $operation->forceFill(['entity_type' => $database.'.dbo.'.$table, 'entity_key' => $key, 'generated_sql' => $result['sql']])->save();
            return redirect()->route('data.index', ['database' => $database, 'table' => $table, 'row' => $key])->with('success', 'Live row updated and audited.');
        } catch (Throwable $exception) {
            if (isset($operation)) {
                app(OperationService::class)->fail($operation, $exception->getMessage());
            }
            return back()->withErrors(['data' => $exception->getMessage()])->withInput();
        }
    }

    public function insert(Request $request, string $database, string $table, SqlTableStudioService $studio): RedirectResponse
    {
        $profile = auth()->user()->serverProfiles()->where('is_active', true)->latest()->firstOrFail();
        $fields = [];
        foreach ((array) $request->input('fields', []) as $column => $value) {
            if (is_string($column) && preg_match('/^[A-Za-z0-9_$-]+$/', $column) && is_scalar($value)) {
                $fields[$column] = (string) $value;
            }
        }
        try {
            $operation = app(OperationService::class)->begin('table.row.created', auth()->id(), $profile->id, [
                'execution_mode' => 'live', 'database' => $database, 'table' => $table, 'fields' => array_keys($fields),
            ], 'high');
            $result = $studio->insert($profile, $database, $table, $fields);
            app(OperationService::class)->complete($operation, ['key' => $result['key']], ['row_count' => $result['row_count'], 'sql' => $result['sql']]);
            $operation->forceFill(['entity_type' => $database.'.dbo.'.$table, 'entity_key' => (string) ($result['key'] ?? ''), 'generated_sql' => $result['sql']])->save();
            $params = ['database' => $database, 'table' => $table];
            if ($result['key'] !== null) {
                $params['row'] = $result['key'];
            }
            return redirect()->route('data.index', $params)->with('success', 'New live row created and audited.');
        } catch (Throwable $exception) {
            if (isset($operation)) {
                app(OperationService::class)->fail($operation, $exception->getMessage());
            }
            return back()->withErrors(['data' => $exception->getMessage()])->withInput();
        }
    }
}
