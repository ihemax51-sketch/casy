<x-app-layout>
    <x-slot name="title">Profile & security</x-slot>
    <x-slot name="header">Profile & security</x-slot>

    <section class="page-heading">
        <div><span class="page-kicker">Owner account</span><h1>Profile &amp; security</h1><p>Manage the single CASY owner account and keep its password secure.</p></div>
        <div class="heading-actions"><span class="security-note"><span class="security-dot"></span>Owner access</span></div>
    </section>

    <div class="profile-grid">
        <div class="profile-main">
            <section class="panel profile-card">@include('profile.partials.update-profile-information-form')</section>
            <section class="panel profile-card">@include('profile.partials.update-password-form')</section>
        </div>
        <aside class="panel profile-card profile-danger">@include('profile.partials.delete-user-form')</aside>
    </div>
</x-app-layout>
