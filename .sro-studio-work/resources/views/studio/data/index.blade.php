<x-app-layout>
    <x-slot name="title">Table studio</x-slot>
    <x-slot name="header">Advanced table studio</x-slot>

    @if($error)
        <div class="inline-alert danger"><strong>Table could not be opened</strong><span>{{ $error }}</span></div>
        <a class="button secondary" href="{{ route('schema.index') }}">Back to schema</a>
    @endif

    @if($data)
        <section class="page-heading">
            <div><span class="page-kicker">Advanced / read-first data studio</span><h1>{{ $data['table'] }}</h1><p><code>{{ $data['database'] }}.dbo.{{ $data['table'] }}</code> - {{ number_format($data['count']) }} matching rows - Primary key: {{ $data['primaryKeys'] ? implode(' + ', $data['primaryKeys']) : 'not defined' }}</p></div>
            <div class="heading-actions"><a class="button secondary" href="{{ route('schema.index') }}">Schema map</a><span class="security-note"><span class="security-dot"></span>{{ $data['write_allowed'] ? 'Allowlisted writes' : 'Read only' }}</span></div>
        </section>

        <section class="toolbar panel">
            <form class="search-form" method="GET" action="{{ route('data.index', ['database' => $data['database'], 'table' => $data['table']]) }}"><span class="search-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m21 21-4.3-4.3m2.3-5.2a7.5 7.5 0 1 1-15 0Z"/></svg></span><input name="q" value="{{ $search }}" placeholder="Search discovered text columns"><button class="button secondary" type="submit">Search</button></form>
            <div class="toolbar-meta"><span class="status-chip {{ $data['write_allowed'] ? 'success' : 'neutral' }}">{{ $data['write_allowed'] ? 'Allowlisted table' : 'Read first' }}</span><span class="muted-label">Page {{ $data['page'] }}</span></div>
        </section>

        @if($data['relations'])
            <div class="info-strip"><span class="info-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.1.1l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1M14 11a5 5 0 0 0-7.1-.1l-2 2A5 5 0 0 0 12 20l1.1-1.1"/></svg></span><div><strong>{{ count($data['relations']) }} foreign-key relationship(s)</strong><p>@foreach($data['relations'] as $relation){{ $relation['column_name'] }} -> {{ $relation['referenced_table'] }}.{{ $relation['referenced_column'] }}{{ !$loop->last ? ' | ' : '' }}@endforeach</p></div></div>
        @endif

        @if($data['write_allowed'])
            <details class="panel data-insert-panel"><summary><span><b aria-hidden="true">+</b> Create a new row</span><small>Schema-aware insert with identity and binary fields protected</small></summary>
                <form method="POST" action="{{ route('data.insert', ['database' => $data['database'], 'table' => $data['table']]) }}" class="data-insert-form">@csrf
                    <div class="field-grid three">@foreach($data['columns'] as $column => $meta) @php($type = strtolower((string) $meta['type_name'])) @php($blocked = $meta['is_identity'] || $meta['is_computed'] || in_array($type, ['timestamp','rowversion','image','binary','varbinary','text','ntext','xml','geography','geometry','hierarchyid'], true)) @if(!$blocked)<label class="field"><span>{{ $column }} @if(!$meta['is_nullable'])<b class="required-mark">*</b>@endif</span><input name="fields[{{ $column }}]" value="{{ old('fields.'.$column) }}" @if(!$meta['is_nullable']) required @endif><small>{{ $meta['type_name'] }} - {{ $meta['is_nullable'] ? 'nullable' : 'required' }}</small></label>@endif @endforeach</div>
                    <button class="button primary" type="submit" onclick="return confirm('Create this row in the live SQL table?')">Create live row</button>
                </form>
            </details>
        @endif

        <div class="data-studio-layout {{ $data['record'] ? 'with-editor' : '' }}">
            <section class="panel data-grid-panel"><div class="data-table-wrap"><table class="data-table"><thead><tr>@foreach($data['visibleColumns'] as $column)<th>{{ $column }}</th>@endforeach@if($data['primaryKeys'])<th></th>@endif</tr></thead><tbody>
                @forelse($data['rows'] as $row)
                    @php($rowKey = collect($data['primaryKeys'])->mapWithKeys(fn ($key) => [$key => $row[$key]])->all())
                    <tr class="{{ $data['normalizedKey'] && $data['normalizedKey'] === $rowKey ? 'selected' : '' }}">@foreach($data['visibleColumns'] as $column)<td title="{{ is_scalar($row[$column] ?? null) ? $row[$column] : '' }}">{{ is_scalar($row[$column] ?? null) ? str($row[$column])->limit(42) : '[data]' }}</td>@endforeach @if($data['primaryKeys'])<td><a class="icon-button" aria-label="Open row" href="{{ route('data.index', ['database' => $data['database'], 'table' => $data['table'], 'row' => json_encode($rowKey), 'q' => $search, 'page' => $data['page']]) }}"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></a></td>@endif</tr>
                @empty
                    <tr><td colspan="{{ max(1, count($data['visibleColumns']) + 1) }}"><div class="empty-compact"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM8 9h8M8 13h5"/></svg></span><p>No rows matched.</p></div></td></tr>
                @endforelse
            </tbody></table></div><div class="pagination"><span>Showing {{ count($data['rows']) }} of {{ number_format($data['count']) }}</span><div>@if($data['page'] > 1)<a class="button secondary" href="{{ request()->fullUrlWithQuery(['page' => $data['page'] - 1]) }}">Previous</a>@endif @if($data['page'] * $data['perPage'] < $data['count'])<a class="button secondary" href="{{ request()->fullUrlWithQuery(['page' => $data['page'] + 1]) }}">Next</a>@endif</div></div></section>

            @if($data['record'] && $data['write_allowed'])
                <aside class="panel data-record-editor"><div class="panel-heading"><div><span class="panel-kicker">Allowlisted live record</span><h2>{{ implode(' + ', $data['primaryKeys']) }}</h2></div><a href="{{ route('data.index', ['database' => $data['database'], 'table' => $data['table'], 'q' => $search, 'page' => $data['page']]) }}" class="icon-button" aria-label="Close editor"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></a></div>
                    <form method="POST" action="{{ route('data.update', ['database' => $data['database'], 'table' => $data['table']]) }}" class="data-record-form">@csrf @method('PUT')<input type="hidden" name="key" value="{{ json_encode($data['normalizedKey']) }}">@foreach($data['columns'] as $column => $meta) @php($type = strtolower((string) $meta['type_name'])) @php($blocked = in_array($column, $data['primaryKeys'], true) || $meta['is_identity'] || $meta['is_computed'] || in_array($type, ['timestamp','rowversion','image','binary','varbinary','text','ntext','xml','geography','geometry','hierarchyid'], true)) @if(!$blocked && array_key_exists($column, $data['record']))<label class="field"><span>{{ $column }}</span><input name="fields[{{ $column }}]" value="{{ old('fields.'.$column, is_scalar($data['record'][$column]) ? trim((string) $data['record'][$column]) : '') }}"><small>{{ $meta['type_name'] }} - {{ $meta['is_nullable'] ? 'nullable' : 'required' }}</small></label>@endif @endforeach<button type="submit" class="button primary full" onclick="return confirm('Apply this update to the live SQL table?')">Save live row</button></form>
                </aside>
            @elseif($data['record'])
                <aside class="panel data-record-editor"><div class="panel-heading"><div><span class="panel-kicker">Read-only record</span><h2>Inspection only</h2></div><a href="{{ route('data.index', ['database' => $data['database'], 'table' => $data['table'], 'q' => $search, 'page' => $data['page']]) }}" class="icon-button" aria-label="Close editor"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></a></div><p class="muted-label">This table is not in the reviewed write allowlist. Use its dedicated domain studio for changes.</p></aside>
            @endif
        </div>
    @endif
</x-app-layout>
