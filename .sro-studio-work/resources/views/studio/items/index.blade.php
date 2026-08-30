<x-app-layout>
    <x-slot name="title">Item Finder</x-slot>

    @php
        $initialItems = collect($items)->map(function ($item) {
            return [
                'id' => (int) $item['ID'],
                'code' => (string) $item['CodeName128'],
                'name' => (string) ($item['NameStrID128'] ?? $item['CodeName128']),
                'icon_url' => $item['_icon'] ? route('icons.thumbnail', $item['_icon']) : null,
            ];
        })->values();
    @endphp

    <section class="control-title item-finder-title">
        <div><span>ITEM LIBRARY</span><h1>Find any item in seconds.</h1><p>Start typing a CodeName or name key. The ID and icon appear immediately.</p></div>
        <a href="{{ route('live.index', ['action' => 'item_player']) }}" class="finder-link"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>Back to Player Control</a>
    </section>

    @if(!$profile || $profile->connection_status !== 'connected')
        <section class="simple-empty"><h2>Connect your server first</h2><p>The item list is loaded from your own _RefObjCommon table.</p><a href="{{ route('server-profile.index') }}">Open connection settings</a></section>
    @else
        <section class="item-finder" x-data="{
            query: @js($search),
            items: @js($initialItems),
            loading: false,
            timer: null,
            copied: null,
            search() {
                clearTimeout(this.timer);
                this.timer = setTimeout(async () => {
                    this.loading = true;
                    try {
                        const response = await fetch(@js(route('lookups.items')) + '?q=' + encodeURIComponent(this.query), { headers: { 'Accept': 'application/json' } });
                        const data = await response.json(); this.items = data.items || [];
                    } finally { this.loading = false }
                }, 160);
            },
            async copy(code) { await navigator.clipboard.writeText(code); this.copied = code; setTimeout(() => { if (this.copied === code) this.copied = null }, 1300) },
          }">
            <div class="item-search-bar">
                <svg viewBox="0 0 24 24"><path d="m21 21-4.3-4.3m2.3-5.2a7.5 7.5 0 1 1-15 0Z"/></svg>
                <input x-model="query" @input="search()" type="search" placeholder="Start typing: ITEM_EU_SWORD..." autocomplete="off" autofocus>
                <span x-show="loading">Searching...</span>
                <b x-show="!loading" x-text="items.length + ' shown'"></b>
            </div>
            @if($error)<div class="simple-flash error"><span>{{ $error }}</span></div>@endif

            <div class="finder-grid">
                <template x-for="item in items" :key="item.id">
                    <article class="finder-card">
                        <div class="finder-icon">
                            <img x-show="item.icon_url" :src="item.icon_url" alt="">
                            <svg x-show="!item.icon_url" viewBox="0 0 24 24"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3ZM4 7.5l8 4.5 8-4.5"/></svg>
                        </div>
                        <div class="finder-copy"><span x-text="item.name"></span><strong x-text="item.code"></strong><small x-text="'Item ID ' + item.id"></small></div>
                        <div class="finder-card-actions">
                            <button type="button" @click="copy(item.code)" :class="copied === item.code ? 'copied' : ''"><svg viewBox="0 0 24 24"><path d="M8 8h12v12H8zM4 16H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v1"/></svg><span x-text="copied === item.code ? 'Copied' : 'Copy'"></span></button>
                            <a :href="@js(route('live.index')) + '?action=item_player&item_code=' + encodeURIComponent(item.code)">Use</a>
                        </div>
                    </article>
                </template>
            </div>
            <div class="finder-empty" x-show="!loading && items.length === 0" x-cloak><h2>No matching items</h2><p>Try fewer letters or search by Item ID key.</p></div>

            @if($total > $perPage && $search === '')
                <nav class="simple-pagination" aria-label="Item pages">
                    <span>Page {{ $page }} of {{ max(1, (int) ceil($total / $perPage)) }} · {{ number_format($total) }} items</span>
                    <div>@if($page > 1)<a href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}">Previous</a>@endif @if($page < ceil($total / $perPage))<a href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}">Next</a>@endif</div>
                </nav>
            @endif
        </section>
    @endif
</x-app-layout>
