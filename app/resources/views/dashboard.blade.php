<x-layouts.app :title="__('ui.dashboard.title')">
    <h1 class="text-3xl font-semibold">{{ __('ui.dashboard.title') }}</h1>
    <div class="mt-8 grid gap-6 md:grid-cols-3">
        <a class="rounded-2xl border border-white/10 bg-white/5 p-6" href="{{ route('reservations.index') }}"><h2 class="font-semibold text-amber-300">{{ __('ui.nav.reservations') }}</h2><p class="mt-2 text-sm text-slate-400">{{ __('ui.dashboard.reservations_help') }}</p></a>
        <a class="rounded-2xl border border-white/10 bg-white/5 p-6" href="{{ route('workspace') }}"><h2 class="font-semibold text-amber-300">{{ __('ui.nav.workspace') }}</h2><p class="mt-2 text-sm text-slate-400">{{ __('ui.dashboard.workspace_help') }}</p></a>
        <a class="rounded-2xl border border-white/10 bg-white/5 p-6" href="{{ route('profile') }}"><h2 class="font-semibold text-amber-300">{{ __('ui.dashboard.security') }}</h2><p class="mt-2 text-sm text-slate-400">{{ __('ui.dashboard.security_help') }}</p></a>
    </div>
</x-layouts.app>
