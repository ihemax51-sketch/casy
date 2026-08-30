<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View|StreamedResponse
    {
        $actions = ActionLog::query()
            ->where('user_id', auth()->id())
            ->when($request->filled('q'), fn ($query) => $query->where(function ($query) use ($request) {
                $term = '%'.trim((string) $request->query('q')).'%';
                $query->where('action', 'like', $term)->orWhere('entity_type', 'like', $term)->orWhere('entity_key', 'like', $term);
            }))
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('risk'), fn ($query) => $query->where('risk_level', (string) $request->query('risk')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('created_at', '<=', $request->query('to')))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        if ($request->query('export') === 'csv') {
            $rows = $actions->getCollection();
            return response()->streamDownload(function () use ($rows): void {
                $handle = fopen('php://output', 'wb');
                fputcsv($handle, ['created_at', 'operation_id', 'action', 'entity', 'risk', 'mode', 'status', 'summary']);
                foreach ($rows as $action) {
                    fputcsv($handle, [$action->created_at?->toIso8601String(), $action->operation_id, $action->action, trim(($action->entity_type ?? 'System').' '.($action->entity_key ?? '')), $action->risk_level ?? 'low', $action->execution_mode, $action->status, json_encode($action->summary ?? [], JSON_UNESCAPED_SLASHES)]);
                }
                fclose($handle);
            }, 'casy-audit-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
        }

        return view('studio.logs.index', ['actions' => $actions, 'search' => (string) $request->query('q', ''), 'filters' => $request->only(['status', 'risk', 'from', 'to'])]);
    }
}
