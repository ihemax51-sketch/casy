<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f6f7f9">
    <meta name="color-scheme" content="light">
    <title>{{ isset($title) ? $title.' / ' : '' }}CASY</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    @vite(['resources/css/app.css', 'resources/css/casy-redesign.css', 'resources/css/player-tool.css', 'resources/js/app.js'])
</head>
<body class="casy-body simple-casy-body">
    @php($activeProfile = auth()->user()?->serverProfiles()->where('is_active', true)->latest()->first())
    <div class="simple-app">
        <header class="simple-header">
            <div class="simple-header-inner">
                <a href="{{ route('live.index') }}" class="simple-brand" aria-label="CASY Player Control">
                    <img src="{{ asset('brand/casy-logo.svg') }}" alt="CASY">
                </a>
                <nav class="simple-nav" aria-label="Main navigation">
                    <a class="{{ request()->routeIs('live.*') ? 'active' : '' }}" href="{{ route('live.index') }}">
                        <svg viewBox="0 0 24 24"><path d="M8 7a4 4 0 1 0 8 0 4 4 0 0 0-8 0ZM4 21a8 8 0 0 1 16 0"/></svg>
                        Player Control
                    </a>
                    <a class="{{ request()->routeIs('items.*') || request()->routeIs('lookups.items') ? 'active' : '' }}" href="{{ route('items.index') }}">
                        <svg viewBox="0 0 24 24"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3ZM4 7.5l8 4.5 8-4.5M12 12v9"/></svg>
                        Item Finder
                    </a>
                </nav>
                <div class="simple-header-actions">
                    <a href="{{ route('server-profile.index') }}" class="simple-connection {{ $activeProfile?->connection_status === 'connected' ? 'online' : '' }}">
                        <span></span>
                        <b>{{ $activeProfile?->connection_status === 'connected' ? 'Server online' : 'Setup server' }}</b>
                    </a>
                    <div class="simple-user" x-data="{ open: false }">
                        <button type="button" @click="open = !open" aria-label="Open account menu">
                            <span>{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                            <svg viewBox="0 0 24 24"><path d="m7 10 5 5 5-5"/></svg>
                        </button>
                        <div class="simple-user-menu" x-show="open" x-transition @click.outside="open = false" x-cloak>
                            <strong>{{ auth()->user()->name }}</strong>
                            <a href="{{ route('server-profile.index') }}">Connection settings</a>
                            <a href="{{ route('profile.edit') }}">Profile &amp; password</a>
                            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="simple-main">
            @if (session('success'))
                <div class="simple-flash success"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg><span>{{ session('success') }}</span></div>
            @endif
            @if (session('warning'))
                <div class="simple-flash warning"><svg viewBox="0 0 24 24"><path d="M12 8v5M12 17h.01M10.3 3.7 2.5 18a2 2 0 0 0 1.8 3h15.4a2 2 0 0 0 1.8-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg><span>{{ session('warning') }}</span></div>
            @endif
            @if ($errors->any())
                <div class="simple-flash error"><svg viewBox="0 0 24 24"><path d="M12 8v5M12 17h.01M10.3 3.7 2.5 18a2 2 0 0 0 1.8 3h15.4a2 2 0 0 0 1.8-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg><span>{{ $errors->first() }}</span></div>
            @endif
            {{ $slot }}
        </main>
    </div>
</body>
</html>
