<x-app-layout>
    <x-slot name="title">{{ $moduleTitle }} Studio</x-slot>
    <x-slot name="header">{{ $moduleTitle }} Studio</x-slot>

    <section class="studio-page-heading">
        <div><span class="page-kicker">{{ $moduleMeta['eyebrow'] }}</span><h1>{{ $moduleTitle }}</h1><p>{{ $moduleMeta['description'] }}</p></div>
        @if($automations)<div class="studio-heading-stats"><span><b>{{ count($automations) }}</b> ready tools</span></div>@endif
    </section>

    @if(!$profile || $profile->connection_status !== 'connected')
        <div class="empty-state large"><div class="empty-illustration" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM8 9h8M8 13h5"/></svg></div><h2>Connect the server database first.</h2><p>CASY loads the tools after a successful connection test.</p><a class="button primary" href="{{ route('server-profile.index') }}">Open connection settings</a></div>
    @elseif($automations)
        @php($selectedTool = $automationAction ?: array_key_first($automations))
        <section class="panel task-workspace" x-data="{ selected: @js($selectedTool) }">
            <aside class="task-menu">
                <div class="task-menu-head"><span class="panel-kicker">Available actions</span><h2>What do you want to do?</h2></div>
                @foreach($automations as $automationKey => $automation)
                    <button type="button" class="task-menu-button" :class="selected === @js($automationKey) ? 'active' : ''" @click="selected = @js($automationKey)">
                        <span class="task-menu-icon"><svg viewBox="0 0 24 24"><path d="m13 2-9 12h7l-1 8 9-12h-7l1-8Z"/></svg></span>
                        <span><strong>{{ $automation['title'] }}</strong><small>{{ $automation['description'] }}</small></span>
                        <svg class="task-menu-arrow" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                @endforeach
            </aside>

            <div class="task-stage">
                @foreach($automations as $automationKey => $automation)
                    <section x-show="selected === @js($automationKey)" x-cloak>
                        <div class="task-stage-head">
                            <div><span class="panel-kicker">Selected action</span><h2>{{ $automation['title'] }}</h2><p>{{ $automation['description'] }}</p></div>
                            <span class="risk-chip {{ $automation['risk'] }}">{{ strtoupper($automation['risk']) }} RISK</span>
                        </div>

                        <form method="POST" action="{{ route('studio.action.run', [$module, $automationKey]) }}" class="task-form">
                            @csrf
                            @if($automation['fields'])
                                <div class="automation-fields">
                                    @foreach($automation['fields'] as $field)
                                        <label class="field"><span>{{ $field['label'] }}</span>
                                            @if($field['type'] === 'select')
                                                <select name="{{ $field['name'] }}" required>@foreach($field['options'] as $value => $label)<option value="{{ $value }}" @selected((string)old($field['name']) === (string)$value)>{{ $label }}</option>@endforeach</select>
                                            @else
                                                <input type="{{ $field['type'] }}" name="{{ $field['name'] }}" value="{{ old($field['name']) }}" @if(str_contains($field['rules'], 'required')) required @endif @if($field['type'] === 'number') step="any" @endif>
                                            @endif
                                            @if($field['hint'])<small>{{ $field['hint'] }}</small>@endif
                                        </label>
                                    @endforeach
                                </div>
                            @else
                                <div class="task-ready-note"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg><span><strong>Ready to run</strong><small>This action does not need extra inputs.</small></span></div>
                            @endif

                            <div class="task-form-actions">
                                @if($automation['readOnly'])
                                    <span>Read-only lookup. No game data will be changed.</span><button class="button primary" type="submit" name="intent" value="preview">Run lookup</button>
                                @else
                                    <span>Preview the exact impact before applying the change.</span>
                                    <div><button class="button secondary" type="submit" name="intent" value="preview">Preview impact</button><details class="execute-confirmation"><summary>Execute change</summary><div class="execute-confirmation-body"><label class="field"><span>Owner password</span><input type="password" name="owner_password" autocomplete="current-password"><small>Required only when executing.</small></label>@if($automation['risk'] === 'critical')<label class="field"><span>Backup reference</span><input name="backup_reference" value="{{ old('backup_reference') }}" placeholder="Backup file or ticket"><small>Critical actions require a recent backup.</small></label><label class="field"><span>Backup time</span><input type="datetime-local" name="backup_at" value="{{ old('backup_at') }}"></label>@endif<button class="button danger" type="submit" name="intent" value="execute" onclick="return confirm('Execute this change on the live server?')">Confirm and execute</button></div></details></div>
                                @endif
                            </div>
                        </form>

                        @if($automationAction === $automationKey && $automationResult)
                            <div class="automation-result {{ $automationResult['mode'] === 'executed' ? 'executed' : 'preview' }}">
                                <div class="automation-result-head"><strong>{{ str($automationResult['mode'])->headline() }}</strong>@foreach($automationResult['messages'] ?? [] as $message)<span>{{ $message }}</span>@endforeach</div>
                                @if($automationResult['rows'] ?? [])
                                    @php($resultColumns = array_slice(array_keys($automationResult['rows'][0]), 0, 8))
                                    <div class="automation-result-table"><table><thead><tr>@foreach($resultColumns as $column)<th>{{ $column }}</th>@endforeach</tr></thead><tbody>@foreach(array_slice($automationResult['rows'], 0, 100) as $row)<tr>@foreach($resultColumns as $column)<td>{{ is_scalar($row[$column] ?? null) ? $row[$column] : '' }}</td>@endforeach</tr>@endforeach</tbody></table></div>
                                @else
                                    <div class="automation-snapshots"><div><span>Before / target</span><pre>{{ json_encode($automationResult['before'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre></div>@if($automationResult['after'] ?? [])<div><span>After</span><pre>{{ json_encode($automationResult['after'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre></div>@endif</div>
                                @endif
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        </section>
    @else
        <div class="empty-state compact"><h2>No finished tool is published here.</h2><p>This page stays out of normal navigation until a working action is available.</p></div>
    @endif
</x-app-layout>
