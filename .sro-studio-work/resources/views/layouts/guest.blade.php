<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#2563eb"><title>CASY - SRO Database Studio</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">@vite(['resources/css/app.css', 'resources/css/casy-redesign.css', 'resources/js/app.js'])
</head>
<body class="guest-body">
    <main class="guest-shell">
        <section class="guest-story"><a href="/" class="guest-brand"><img src="{{ asset('brand/casy-logo.svg') }}" alt="CASY"></a><div class="guest-story-copy"><span class="soft-badge">Visual SRO management</span><h1>Your whole server.<br><span>Clear, visual, controlled.</span></h1><p>Manage items, NPC shops, characters, worlds, drops and resources without hand-writing database code.</p><div class="guest-points"><div><b>01</b><span><strong>See the real structure</strong><small>CASY maps your own SQL schema.</small></span></div><div><b>02</b><span><strong>Preview every change</strong><small>Understand the result before applying it.</small></span></div><div><b>03</b><span><strong>Keep full control</strong><small>Audited, reversible workflows.</small></span></div></div></div><div class="guest-orb one"></div><div class="guest-orb two"></div></section>
        <section class="guest-form-panel"><div class="guest-form-wrap"><img class="guest-mobile-logo" src="{{ asset('brand/casy-logo.svg') }}" alt="CASY">{{ $slot }}<p class="guest-help">CASY runs on your server and connects directly to your own databases.</p></div></section>
    </main>
</body>
</html>
