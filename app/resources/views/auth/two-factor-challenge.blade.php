<x-layouts.app :title="__('ui.profile.two_factor')">
    <div class="mx-auto grid max-w-3xl gap-6 md:grid-cols-2">
        <form class="space-y-5 rounded-2xl border border-white/10 bg-white/5 p-8" method="POST" action="{{ route('two-factor.login') }}">
            @csrf
            <h1 class="text-xl font-semibold">{{ __('ui.auth.authenticator_code') }}</h1>
            <input class="w-full rounded bg-slate-900 p-3" type="text" inputmode="numeric" name="code" autocomplete="one-time-code" autofocus>
            <button class="rounded bg-amber-400 px-4 py-3 font-semibold text-slate-950" type="submit">{{ __('ui.auth.verify') }}</button>
        </form>
        <form class="space-y-5 rounded-2xl border border-white/10 bg-white/5 p-8" method="POST" action="{{ route('two-factor.login') }}">
            @csrf
            <h2 class="text-xl font-semibold">{{ __('ui.auth.recovery_code') }}</h2>
            <input class="w-full rounded bg-slate-900 p-3" type="text" name="recovery_code" autocomplete="one-time-code">
            <button class="rounded border border-white/20 px-4 py-3" type="submit">{{ __('ui.auth.use_recovery_code') }}</button>
        </form>
    </div>
</x-layouts.app>
