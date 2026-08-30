<?php

namespace App\Http\Controllers;

use App\Services\QueryReferenceCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AutomationLibraryController extends Controller
{
    public function __invoke(Request $request, QueryReferenceCatalog $queries): View
    {
        $filter = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('q', ''));
        $rows = array_values(array_filter($queries->all(), function (array $row) use ($filter, $search): bool {
            if ($filter !== 'all' && $row['status'] !== $filter) {
                return false;
            }
            return $search === '' || str_contains(strtolower($row['title'].' '.$row['category'].' '.$row['number']), strtolower($search));
        }));

        return view('automations.index', [
            'rows' => $rows,
            'summary' => $queries->summary(),
            'filter' => $filter,
            'search' => $search,
        ]);
    }
}
