<x-layouts.app :title="__('ui.auth.reset_password')">
    <form class="mx-auto max-w-md space-y-5 rounded-2xl border border-white/10 bg-white/5 p-8" method="POST" action="{{ route('password.email') }}">
        @csrf
        <h1 class="text-2xl font-semibold">{{ __('ui.auth.reset_password') }}</h1>
        <p class="text-sm text-slate-400">{{ __('ui.auth.reset_link_notice') }}</p>
        <label class="block">{{ __('ui.auth.email') }}<input class="mt-2 w-full rounded bg-slate-900 p-3" type="email" name="email" required autofocus></label>
        <button class="rounded bg-amber-400 px-4 py-3 font-semibold text-slate-950" type="submit">{{ __('ui.auth.send_reset_link') }}</button>
    </form>
</x-layouts.app>
