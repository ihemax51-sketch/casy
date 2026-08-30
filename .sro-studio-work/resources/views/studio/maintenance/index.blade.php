<x-app-layout>
    <x-slot name="title">Maintenance vault</x-slot>
    <x-slot name="header">Maintenance vault</x-slot>

    <section class="page-heading"><div><span class="page-kicker">Operations / emergency vault</span><h1>Guarded maintenance tools.</h1><p>Emergency actions stay locked behind owner re-authentication, a typed database name and manual backup proof. This workspace is dry-run first.</p></div><div class="heading-actions"><span class="security-note"><span class="security-dot"></span>Locked by default</span></div></section>
    @if($errors->any())<div class="inline-alert danger"><strong>Maintenance request blocked</strong><span>{{ $errors->first() }}</span></div>@endif
    @if($preview)<div class="info-strip"><span class="info-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h14v16H5zM8 8h8M8 12h6M8 16h4"/></svg></span><div><strong>Dry-run: {{ $preview['action'] }}</strong><p>{{ $preview['message'] }} Backup: {{ $preview['backup_reference'] }} ({{ $preview['backup_at'] }}).</p></div></div>@endif

    <div class="maintenance-grid">
        <section class="panel maintenance-actions"><div class="panel-heading"><div><span class="panel-kicker">Allowlisted operations</span><h2>Choose an emergency action</h2></div><span class="risk-chip critical">CRITICAL</span></div>
            @foreach($actions as $action)<div class="maintenance-action"><span class="module-icon rose" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v18M3 12h18"/></svg></span><div><strong>{{ str($action)->replace('.', ' / ')->title() }}</strong><small>Locked - dry-run available - reviewed adapter required</small></div></div>@endforeach
        </section>
        <section class="panel maintenance-form"><div class="panel-heading"><div><span class="panel-kicker">Step-up confirmation</span><h2>Run a dry-run preview</h2></div></div>
            <form method="POST" action="{{ route('maintenance.dry-run') }}" class="form-stack">@csrf
                <label class="field"><span>Action</span><select name="action" required>@foreach($actions as $action)<option value="{{ $action }}">{{ str($action)->replace('.', ' / ')->title() }}</option>@endforeach</select></label>
                <label class="field"><span>Complete shard database name</span><input name="database_name" value="{{ old('database_name', $profile?->shard_database) }}" required></label>
                <label class="field"><span>Backup reference</span><input name="backup_reference" value="{{ old('backup_reference') }}" placeholder="Ticket, file path or operator reference" required></label>
                <label class="field"><span>Backup time</span><input type="datetime-local" name="backup_at" value="{{ old('backup_at') }}" required><small>Must be less than 24 hours old.</small></label>
                <label class="field"><span>Owner password</span><input type="password" name="password" autocomplete="current-password" required></label>
                <button class="button primary" type="submit">Preview impact</button>
            </form>
        </section>
    </div>
</x-app-layout>
