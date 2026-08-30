<x-guest-layout>
    <span class="page-kicker">First-time setup</span><h2>Create your CASY account.</h2><p>Your local administrator account keeps the studio private.</p>
    <form method="POST" action="{{ route('register') }}">@csrf
        <div><label for="name">Name</label><input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name"><x-input-error :messages="$errors->get('name')" class="mt-2" /></div>
        <div class="mt-4"><label for="email">Email address</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="username"><x-input-error :messages="$errors->get('email')" class="mt-2" /></div>
        <div class="mt-4"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="new-password"><x-input-error :messages="$errors->get('password')" class="mt-2" /></div>
        <div class="mt-4"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"><x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" /></div>
        <div class="flex mt-4"><a class="underline" href="{{ route('login') }}">Already registered?</a><button type="submit">Create account</button></div>
    </form>
</x-guest-layout>
