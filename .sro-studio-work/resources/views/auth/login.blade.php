<x-guest-layout>
    <span class="page-kicker">Welcome back</span><h2>Sign in to CASY.</h2><p>Open your visual SRO database workspace.</p>
    <x-auth-session-status class="mb-4" :status="session('status')" />
    <form method="POST" action="{{ route('login') }}">@csrf
        <div><label for="email">Email address</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"><x-input-error :messages="$errors->get('email')" class="mt-2" /></div>
        <div class="mt-4"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"><x-input-error :messages="$errors->get('password')" class="mt-2" /></div>
        <div class="flex mt-4"><label class="remember-label"><input type="checkbox" name="remember"> <span>Remember me</span></label>@if(Route::has('password.request'))<a class="underline" href="{{ route('password.request') }}">Forgot password?</a>@endif</div>
        <div class="flex mt-4">@if(app()->environment('testing') && Route::has('register'))<span class="form-register-link">New here? <a class="underline" href="{{ route('register') }}">Create account</a></span>@endif<button type="submit">Sign in</button></div>
    </form>
</x-guest-layout>
