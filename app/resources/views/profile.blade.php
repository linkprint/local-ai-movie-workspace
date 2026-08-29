<x-layouts.app :title="__('ui.profile.title')">
    <h1 class="text-3xl font-semibold">{{ __('ui.profile.title') }}</h1>
    <div class="mt-8 grid gap-6 md:grid-cols-2">
        <section class="rounded-2xl border border-white/10 bg-white/5 p-6">
            <h2 class="text-xl font-semibold">{{ __('ui.profile.two_factor') }}</h2>
            @if(! auth()->user()->two_factor_secret)
                <p class="my-4 text-sm text-slate-400">{{ __('ui.profile.totp_required') }}</p>
                <form method="POST" action="/user/two-factor-authentication">@csrf<button class="rounded bg-amber-400 px-4 py-3 font-semibold text-slate-950" type="submit">{{ __('ui.profile.enable_totp') }}</button></form>
            @elseif(! auth()->user()->two_factor_confirmed_at)
                <div class="my-5 rounded bg-white p-4">{!! auth()->user()->twoFactorQrCodeSvg() !!}</div>
                <form class="space-y-3" method="POST" action="/user/confirmed-two-factor-authentication">@csrf<input class="w-full rounded bg-slate-900 p-3" name="code" inputmode="numeric" autocomplete="one-time-code" required><button class="rounded bg-amber-400 px-4 py-3 font-semibold text-slate-950" type="submit">{{ __('ui.profile.confirm_totp') }}</button></form>
            @else
                <p class="my-4 text-emerald-300">{{ __('ui.profile.totp_enabled') }}</p>
                <details class="mb-4 text-sm"><summary>{{ __('ui.profile.show_recovery_codes') }}</summary><ul class="mt-3 space-y-1 font-mono">@foreach(auth()->user()->recoveryCodes() as $code)<li>{{ $code }}</li>@endforeach</ul></details>
                <form method="POST" action="/user/two-factor-authentication">@csrf @method('DELETE')<button class="text-red-300" type="submit">{{ __('ui.profile.disable_totp') }}</button></form>
            @endif
        </section>
        <section class="rounded-2xl border border-white/10 bg-white/5 p-6">
            <h2 class="text-xl font-semibold">{{ __('ui.profile.change_password') }}</h2>
            <form class="mt-5 space-y-4" method="POST" action="/user/password">@csrf @method('PUT')
                <input class="w-full rounded bg-slate-900 p-3" type="password" name="current_password" placeholder="{{ __('ui.profile.current_password') }}" required>
                <input class="w-full rounded bg-slate-900 p-3" type="password" name="password" placeholder="{{ __('ui.auth.new_password') }}" required>
                <input class="w-full rounded bg-slate-900 p-3" type="password" name="password_confirmation" placeholder="{{ __('ui.auth.confirm_password') }}" required>
                <button class="rounded border border-white/20 px-4 py-3" type="submit">{{ __('ui.profile.update_password') }}</button>
            </form>
        </section>
    </div>
</x-layouts.app>
