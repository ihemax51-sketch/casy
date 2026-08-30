<x-app-layout>
    <x-slot name="title">Automation Library</x-slot>
    <x-slot name="header">Automation Library</x-slot>

    <section class="studio-page-heading query-library-heading">
        <div><span class="page-kicker">Your supplied SQL library</span><h1>95 tasks, mapped to real CASY tools.</h1><p>This is the exact coverage map. A Ready task opens its working button. Unsafe cleanup scripts stay locked, and schema-specific scripts stay marked until their adapter is verified.</p></div>
        <div class="studio-heading-stats"><span><b>{{ $summary['ready'] ?? 0 }}</b> ready</span><span><b>{{ $summary['review'] ?? 0 }}</b> review</span><span><b>{{ ($summary['emergency'] ?? 0) + ($summary['package'] ?? 0) }}</b> guarded</span></div>
    </section>

    <section class="panel query-library-panel">
        <form method="GET" class="query-library-toolbar">
            <div class="compact-search"><svg viewBox="0 0 24 24"><path d="m21 21-4.3-4.3m2.3-5.2a7.5 7.5 0 1 1-15 0Z"/></svg><input name="q" value="{{ $search }}" placeholder="Search the 95 tasks..."><button class="button secondary" type="submit">Search</button></div>
            <div class="query-status-tabs">@foreach(['all' => 'All 95', 'ready' => 'Ready', 'review' => 'Needs adapter', 'package' => 'Package', 'emergency' => 'Emergency', 'incompatible' => 'Incompatible'] as $value => $label)<a class="{{ $filter === $value ? 'active' : '' }}" href="{{ route('automations.index', ['status' => $value, 'q' => $search]) }}">{{ $label }}</a>@endforeach</div>
        </form>
        <div class="query-library-list">
            @forelse($rows as $row)
                <article class="query-library-row">
                    <span class="query-number">{{ str_pad((string)$row['number'], 2, '0', STR_PAD_LEFT) }}</span>
                    <div class="query-library-copy"><strong>{{ $row['title'] }}</strong><small>{{ $row['category'] }}@if($row['module']) · {{ str($row['module'])->headline() }}@endif</small></div>
                    <span class="query-status {{ $row['status'] }}">{{ $row['statusLabel'] }}</span>
                    @if($row['link'])<a class="button {{ $row['status'] === 'ready' ? 'primary' : 'secondary' }}" href="{{ $row['link'] }}">Open tool</a>@else<span class="query-no-action">No live button</span>@endif
                </article>
            @empty
                <div class="empty-state compact"><h3>No tasks match this filter.</h3><p>Clear the search or choose another status.</p></div>
            @endforelse
        </div>
    </section>
</x-app-layout>
