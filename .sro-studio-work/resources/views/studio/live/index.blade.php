<x-app-layout>
    <x-slot name="title">Player Control</x-slot>

    @php
        $groups = collect($actions)->groupBy('group');
        $availableKeys = collect($actions)->where('available', true)->pluck('key');
        $requestedAction = old('action', request('action', 'silk'));
        $initialAction = $availableKeys->contains($requestedAction) ? $requestedAction : ($availableKeys->first() ?? 'silk');
        $initialMode = old('target_mode', request('target_mode', 'name'));
        $initialTarget = old('target_value', request('target_value', ''));
        $actionMeta = collect($actions)->mapWithKeys(fn ($action) => [$action['key'] => ['label' => $action['label'], 'description' => $action['description']]]);
    @endphp

    <section class="control-title">
        <div>
            <span>CASY PLAYER TOOL</span>
            <h1>Everything you need. One screen.</h1>
            <p>Find a character once, choose an action, and apply it immediately.</p>
        </div>
        <a href="{{ route('items.index') }}" class="finder-link">
            <svg viewBox="0 0 24 24"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3ZM4 7.5l8 4.5 8-4.5"/></svg>
            Open Item Finder
        </a>
    </section>

    @if(!$profile || $profile->connection_status !== 'connected')
        <section class="simple-empty">
            <svg viewBox="0 0 24 24"><path d="M7 12h10M12 7v10M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/></svg>
            <h2>Connect your server first</h2>
            <p>Player Control needs the active SHARD and KMTGuard databases.</p>
            <a href="{{ route('server-profile.index') }}">Open connection settings</a>
        </section>
    @elseif($error && !$actions)
        <section class="simple-empty error"><h2>Player Control could not load</h2><p>{{ $error }}</p></section>
    @else
        <form method="POST" action="{{ route('live.execute') }}" class="player-control"
              x-data="{
                action: @js($initialAction),
                meta: @js($actionMeta),
                targetMode: @js($initialMode),
                targetValue: @js($initialTarget),
                character: @js($character),
                lookupLoading: false,
                lookupError: @js($error),
                spawnMode: @js(old('spawn_mode', 'player')),
                itemQuery: @js(old('item_code', request('item_code', ''))),
                itemResults: [],
                itemOpen: false,
                itemTimer: null,
                monsterQuery: '',
                monsterResults: [],
                monsterOpen: false,
                monsterTimer: null,
                needsTarget() { return !['server_notice','item_online'].includes(this.action) && !(this.action === 'spawn' && this.spawnMode === 'position') },
                async findPlayer() {
                    if (!this.targetValue.trim()) { this.lookupError = 'Enter a CharID or character name.'; return }
                    this.lookupLoading = true; this.lookupError = '';
                    try {
                        const response = await fetch(@js(route('live.player')) + '?mode=' + encodeURIComponent(this.targetMode) + '&value=' + encodeURIComponent(this.targetValue), { headers: { 'Accept': 'application/json' } });
                        const data = await response.json();
                        if (!response.ok || !data.ok) throw new Error(data.message || 'Character was not found.');
                        this.character = data.character;
                    } catch (error) { this.character = null; this.lookupError = error.message }
                    finally { this.lookupLoading = false }
                },
                searchItems() {
                    clearTimeout(this.itemTimer);
                    if (this.itemQuery.trim().length < 2) { this.itemResults = []; this.itemOpen = false; return }
                    this.itemTimer = setTimeout(async () => {
                        const response = await fetch(@js(route('lookups.items')) + '?q=' + encodeURIComponent(this.itemQuery), { headers: { 'Accept': 'application/json' } });
                        const data = await response.json(); this.itemResults = data.items || []; this.itemOpen = true;
                    }, 180);
                },
                chooseItem(item) { this.itemQuery = item.code; this.itemOpen = false },
                searchMonsters() {
                    clearTimeout(this.monsterTimer);
                    if (this.monsterQuery.trim().length < 2) { this.monsterResults = []; this.monsterOpen = false; return }
                    this.monsterTimer = setTimeout(async () => {
                        const response = await fetch(@js(route('lookups.monsters')) + '?q=' + encodeURIComponent(this.monsterQuery), { headers: { 'Accept': 'application/json' } });
                        const data = await response.json(); this.monsterResults = data.monsters || []; this.monsterOpen = true;
                    }, 180);
                },
                chooseMonster(monster) { this.monsterQuery = monster.code; this.$refs.monsterId.value = monster.id; this.monsterOpen = false },
              }"
              @submit="if (needsTarget() && !targetValue.trim()) { $event.preventDefault(); lookupError = 'Choose the player first.' } else if (['disconnect','spawn','item_online'].includes(action) && !confirm('Run this action now?')) { $event.preventDefault() }">
            @csrf
            <input type="hidden" name="action" :value="action">
            <input type="hidden" name="target_mode" :value="targetMode">
            <input type="hidden" name="target_value" :value="targetValue">

            <section class="player-lookup">
                <div class="lookup-heading">
                    <span class="step-number">1</span>
                    <div><h2>Choose the player</h2><p>Use the exact name or CharID. You only enter it once.</p></div>
                </div>
                <div class="lookup-row">
                    <div class="identifier-switch">
                        <button type="button" :class="targetMode === 'name' ? 'active' : ''" @click="targetMode = 'name'; character = null">Name</button>
                        <button type="button" :class="targetMode === 'id' ? 'active' : ''" @click="targetMode = 'id'; character = null">CharID</button>
                    </div>
                    <div class="lookup-input">
                        <svg viewBox="0 0 24 24"><path d="m21 21-4.3-4.3m2.3-5.2a7.5 7.5 0 1 1-15 0Z"/></svg>
                        <input x-model="targetValue" @keydown.enter.prevent="findPlayer()" @input="character = null; lookupError = ''" type="text" :inputmode="targetMode === 'id' ? 'numeric' : 'text'" :placeholder="targetMode === 'id' ? 'Enter CharID' : 'Enter character name'" autocomplete="off">
                    </div>
                    <button class="find-player-button" type="button" @click="findPlayer()" :disabled="lookupLoading">
                        <span x-show="!lookupLoading">Find player</span><span x-show="lookupLoading" x-cloak>Checking...</span>
                    </button>
                </div>
                <p class="lookup-error" x-show="lookupError" x-text="lookupError"></p>
                <div class="character-strip" x-show="character" x-cloak>
                    <div class="character-avatar" x-text="character?.name?.charAt(0)?.toUpperCase()"></div>
                    <div class="character-name"><strong x-text="character?.name"></strong><small>CharID <span x-text="character?.id"></span></small></div>
                    <dl><div><dt>Level</dt><dd x-text="character?.level"></dd></div><div><dt>Gold</dt><dd x-text="Number(character?.gold || 0).toLocaleString()"></dd></div><div><dt>STR</dt><dd x-text="character?.strength"></dd></div><div><dt>INT</dt><dd x-text="character?.intellect"></dd></div><div><dt>Skill points</dt><dd x-text="Number(character?.skill_points || 0).toLocaleString()"></dd></div><div><dt>Region</dt><dd x-text="character?.region_id"></dd></div></dl>
                </div>
                <div class="target-not-needed" x-show="!needsTarget()" x-cloak><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg>This action does not need a selected player.</div>
            </section>

            <div class="control-workspace">
                <aside class="action-rail">
                    <div class="rail-heading"><span class="step-number">2</span><div><h2>Choose an action</h2><p>Only the tools you asked for.</p></div></div>
                    @foreach($groups as $group => $groupActions)
                        <div class="action-group">
                            <h3>{{ $group }}</h3>
                            <div class="action-list">
                                @foreach($groupActions as $tool)
                                    <button type="button" class="action-choice" :class="action === @js($tool['key']) ? 'active' : ''" @click="action = @js($tool['key'])" @disabled(!$tool['available'])>
                                        <span class="action-choice-icon">
                                            @switch($tool['key'])
                                                @case('silk')<svg viewBox="0 0 24 24"><path d="M12 3v18M16.5 7c-1.2-1.8-8-1.8-8 1.5 0 3.5 8 1 8 5 0 3.5-6.8 3.4-9 1.2"/></svg>@break
                                                @case('level')<svg viewBox="0 0 24 24"><path d="M12 20V4m-6 6 6-6 6 6"/></svg>@break
                                                @case('gold')<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M15 8.5c-.8-1-5.5-1-5.5 1.1 0 2.2 5.5.7 5.5 3.2 0 2.2-4.7 2.2-6 1M12 6.5v11"/></svg>@break
                                                @case('stats')<svg viewBox="0 0 24 24"><path d="M4 20h16M6 17l4-4 3 2 5-7"/></svg>@break
                                                @case('skill_points')<svg viewBox="0 0 24 24"><path d="m12 3 2.3 4.7L20 8.5l-4 3.9.9 5.6-4.9-2.6L7.1 18l.9-5.6-4-3.9 5.7-.8L12 3Z"/></svg>@break
                                                @case('position')<svg viewBox="0 0 24 24"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>@break
                                                @case('town')<svg viewBox="0 0 24 24"><path d="M3 21h18M5 21V9l7-5 7 5v12M9 21v-6h6v6"/></svg>@break
                                                @case('refresh')<svg viewBox="0 0 24 24"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>@break
                                                @case('player_notice')<svg viewBox="0 0 24 24"><path d="M4 5h16v12H8l-4 4V5ZM8 9h8M8 13h5"/></svg>@break
                                                @case('server_notice')<svg viewBox="0 0 24 24"><path d="m4 13 12-5v8L4 11v2Zm12-2 4-2v6l-4-2M6 14l1 5h4l-1.5-4"/></svg>@break
                                                @case('disconnect')<svg viewBox="0 0 24 24"><path d="M10 4H5v16h5M14 8l4 4-4 4M18 12H9"/></svg>@break
                                                @case('spawn')<svg viewBox="0 0 24 24"><path d="M5 20v-7l3-3 4 2 4-5 3 3v10M8 6h.01M16 3h.01"/></svg>@break
                                                @default<svg viewBox="0 0 24 24"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/></svg>
                                            @endswitch
                                        </span>
                                        <span><strong>{{ $tool['label'] }}</strong><small>{{ $tool['available'] ? $tool['description'] : 'Required KMTGuard procedure is not installed.' }}</small></span>
                                        <svg class="choice-arrow" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </aside>

                <section class="action-form-panel">
                    <div class="selected-action-heading">
                        <span class="step-number">3</span>
                        <div><span>READY TO APPLY</span><h2 x-text="meta[action]?.label">{{ data_get($actionMeta, $initialAction.'.label') }}</h2><p x-text="meta[action]?.description">{{ data_get($actionMeta, $initialAction.'.description') }}</p></div>
                    </div>

                    <div class="action-fields" x-show="action === 'silk'" x-cloak>
                        <label><span>Silk type</span><select name="silk_type" :disabled="action !== 'silk'" required><option value="normal">Normal silk</option><option value="gift">Gift silk</option><option value="point">Silk points</option></select></label>
                        <label><span>Amount to add</span><input name="amount" type="number" min="1" max="2000000000" value="{{ old('amount') }}" :disabled="action !== 'silk'" required placeholder="Example: 1000"></label>
                    </div>
                    <div class="action-fields one" x-show="action === 'level'" x-cloak>
                        <label><span>New level</span><input name="level" type="number" min="1" max="255" value="{{ old('level') }}" :disabled="action !== 'level'" required placeholder="Example: 120"></label>
                    </div>
                    <div class="action-fields one" x-show="action === 'gold'" x-cloak>
                        <label><span>Gold to add</span><input name="amount" type="number" min="1" max="2000000000" value="{{ old('amount') }}" :disabled="action !== 'gold'" required placeholder="Example: 1000000"></label>
                    </div>
                    <div class="action-fields" x-show="action === 'stats'" x-cloak>
                        <label><span>Stat</span><select name="stat_type" :disabled="action !== 'stats'" required><option value="strength">Strength (STR)</option><option value="intellect">Intelligence (INT)</option></select></label>
                        <label><span>Points to add</span><input name="amount" type="number" min="1" max="32747" value="{{ old('amount') }}" :disabled="action !== 'stats'" required placeholder="Example: 50"></label>
                    </div>
                    <div class="action-fields one" x-show="action === 'skill_points'" x-cloak>
                        <label><span>Skill points to add</span><input name="amount" type="number" min="1" max="2000000000" value="{{ old('amount') }}" :disabled="action !== 'skill_points'" required placeholder="Example: 100000"></label>
                    </div>

                    <div class="action-fields coordinates" x-show="action === 'position' || (action === 'spawn' && spawnMode === 'position')" x-cloak>
                        <label><span>World ID</span><input name="world_id" type="number" min="1" max="65535" value="{{ old('world_id') }}" :disabled="!(action === 'position' || (action === 'spawn' && spawnMode === 'position'))" required placeholder="1"></label>
                        <label><span>Region ID</span><input name="region_id" type="number" min="-32768" max="32767" value="{{ old('region_id') }}" :disabled="!(action === 'position' || (action === 'spawn' && spawnMode === 'position'))" required placeholder="25000"></label>
                        <label><span>Position X</span><input name="pos_x" type="number" value="{{ old('pos_x') }}" :disabled="!(action === 'position' || (action === 'spawn' && spawnMode === 'position'))" required placeholder="982"></label>
                        <label><span>Position Y</span><input name="pos_y" type="number" value="{{ old('pos_y') }}" :disabled="!(action === 'position' || (action === 'spawn' && spawnMode === 'position'))" required placeholder="0"></label>
                        <label><span>Position Z</span><input name="pos_z" type="number" value="{{ old('pos_z') }}" :disabled="!(action === 'position' || (action === 'spawn' && spawnMode === 'position'))" required placeholder="140"></label>
                        <label x-show="action === 'spawn'"><span>Spawn radius</span><input name="radius" type="number" min="0" max="1000000" value="{{ old('radius', 0) }}" :disabled="!(action === 'spawn' && spawnMode === 'position')" required></label>
                    </div>

                    <div class="no-fields" x-show="['town','refresh','disconnect'].includes(action)" x-cloak>
                        <svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg><div><strong>No extra information needed</strong><span>The selected player is enough for this action.</span></div>
                    </div>

                    <div class="action-fields notice-fields" x-show="action === 'player_notice' || action === 'server_notice'" x-cloak>
                        <label><span>Notice style</span><select name="notice_type" :disabled="!(action === 'player_notice' || action === 'server_notice')" required><option value="2">Notice</option><option value="3">Warning</option><option value="4">Right side</option><option value="8">Yellow right</option><option value="9">Red right</option></select></label>
                        <label class="wide"><span>Message</span><textarea name="notice" maxlength="500" :disabled="!(action === 'player_notice' || action === 'server_notice')" required placeholder="Write the message...">{{ old('notice') }}</textarea></label>
                    </div>

                    <div class="spawn-fields" x-show="action === 'spawn'" x-cloak>
                        <div class="form-segment"><button type="button" :class="spawnMode === 'player' ? 'active' : ''" @click="spawnMode = 'player'">Near selected player</button><button type="button" :class="spawnMode === 'position' ? 'active' : ''" @click="spawnMode = 'position'">At position</button></div>
                        <input type="hidden" name="spawn_mode" :value="spawnMode" :disabled="action !== 'spawn'">
                        <div class="action-fields">
                            <label class="lookup-field"><span>Search monster / unique</span><input x-model="monsterQuery" @input="searchMonsters()" @focus="searchMonsters()" type="search" :disabled="action !== 'spawn'" placeholder="Type the first letters of CodeName..." autocomplete="off"><div class="lookup-dropdown" x-show="monsterOpen" @click.outside="monsterOpen = false" x-cloak><template x-for="monster in monsterResults" :key="monster.id"><button type="button" @click="chooseMonster(monster)"><span><strong x-text="monster.code"></strong><small x-text="monster.name"></small></span><b x-text="'ID ' + monster.id"></b></button></template><p x-show="monsterResults.length === 0">No monsters found.</p></div></label>
                            <label><span>Monster ID</span><input x-ref="monsterId" name="monster_id" type="number" min="1" value="{{ old('monster_id') }}" :disabled="action !== 'spawn'" required placeholder="Filled from search"></label>
                        </div>
                    </div>

                    <div class="reward-fields" x-show="action === 'item_player' || action === 'item_online'" x-cloak>
                        <label class="lookup-field"><span>Item CodeName</span><input name="item_code" x-model="itemQuery" @input="searchItems()" @focus="searchItems()" type="search" maxlength="128" :disabled="!(action === 'item_player' || action === 'item_online')" required placeholder="Type the first letters..." autocomplete="off"><div class="lookup-dropdown item-results" x-show="itemOpen" @click.outside="itemOpen = false" x-cloak><template x-for="item in itemResults" :key="item.id"><button type="button" @click="chooseItem(item)"><span class="lookup-thumb"><img x-show="item.icon_url" :src="item.icon_url" alt=""><svg x-show="!item.icon_url" viewBox="0 0 24 24"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/></svg></span><span><strong x-text="item.code"></strong><small x-text="item.name"></small></span><b x-text="'ID ' + item.id"></b></button></template><p x-show="itemResults.length === 0">No items found.</p></div></label>
                        <div class="action-fields">
                            <label><span>Quantity</span><input name="quantity" type="number" min="1" max="1000000" value="{{ old('quantity', 1) }}" :disabled="!(action === 'item_player' || action === 'item_online')" required></label>
                            <label><span>Plus</span><input name="plus" type="number" min="0" max="255" value="{{ old('plus', 0) }}" :disabled="!(action === 'item_player' || action === 'item_online')" required></label>
                        </div>
                        <a class="browse-items-inline" href="{{ route('items.index') }}" target="_blank">Browse the full Item Finder</a>
                    </div>

                    <div class="auto-refresh-note" x-show="['level','stats','skill_points'].includes(action)" x-cloak>
                        <svg viewBox="0 0 24 24"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>
                        Self teleport is sent automatically after this database update.
                    </div>

                    <div class="apply-bar">
                        <div><strong x-text="needsTarget() ? (character ? character.name : 'Select a player above') : 'Server action'"></strong><small x-text="needsTarget() ? 'The action will apply to this character.' : 'No character target is required.'"></small></div>
                        <button type="submit" :disabled="needsTarget() && !targetValue.trim()"><span>Run action</span><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></button>
                    </div>
                </section>
            </div>
        </form>
    @endif
</x-app-layout>
