<x-layouts.app :title="__('ui.auth.confirm_password')">
    <form class="mx-auto max-w-md space-y-5 rounded-2xl border border-white/10 bg-white/5 p-8" method="POST" action="{{ route('password.confirm') }}">
        @csrf
        <h1 class="text-xl font-semibold">{{ __('ui.auth.confirm_your_password') }}</h1>
        <input class="w-full rounded bg-slate-900 p-3" type="password" name="password" required autocomplete="current-password">
        <button class="rounded bg-amber-400 px-4 py-3 font-semibold text-slate-950" type="submit">{{ __('ui.common.confirm') }}</button>
    </form>
</x-layouts.app>
