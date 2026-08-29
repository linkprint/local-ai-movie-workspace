<x-layouts.app :title="__('ui.auth.sign_in')">
    <div class="mx-auto max-w-md rounded-2xl border border-white/10 bg-white/5 p-8">
        <h1 class="text-2xl font-semibold">{{ __('ui.auth.company_sign_in') }}</h1>
        <p class="mt-2 text-sm text-slate-400">{{ __('ui.auth.no_registration') }}</p>
        <form class="mt-8 space-y-5" method="POST" action="{{ route('login') }}">
            @csrf
            <label class="block">{{ __('ui.auth.email') }}<input class="mt-2 w-full rounded bg-slate-900 p-3" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
            <label class="block">{{ __('ui.auth.password') }}<input class="mt-2 w-full rounded bg-slate-900 p-3" type="password" name="password" required autocomplete="current-password"></label>
            <label class="flex gap-2 text-sm"><input type="checkbox" name="remember"> {{ __('ui.auth.remember_me') }}</label>
            <button class="w-full rounded bg-amber-400 px-4 py-3 font-semibold text-slate-950" type="submit">{{ __('ui.auth.sign_in') }}</button>
        </form>
        <a class="mt-5 inline-block text-sm text-amber-300" href="{{ route('password.request') }}">{{ __('ui.auth.forgot_password') }}</a>
    </div>
</x-layouts.app>
