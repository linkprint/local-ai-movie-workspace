<x-layouts.app :title="__('ui.recovery.title')">
    @vite('resources/js/recovery.js')
    <div class="space-y-6" data-recovery-manager>
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-medium uppercase tracking-[0.22em] text-red-300">{{ __('ui.recovery.private_media') }}</p>
                <h1 class="mt-2 text-3xl font-semibold">{{ __('ui.recovery.title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-400">{{ __('ui.recovery.intro') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="inline-flex rounded-lg border border-fuchsia-400/25 px-4 py-2 text-sm text-fuchsia-100" href="{{ route('workspace.images.index') }}">{{ __('ui.recovery.back_images') }}</a>
                <a class="inline-flex rounded-lg border border-amber-400/25 px-4 py-2 text-sm text-amber-100" href="{{ route('workspace.videos.index') }}">{{ __('ui.recovery.back_videos') }}</a>
            </div>
        </div>

        <section class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border border-fuchsia-400/20 bg-fuchsia-400/5 p-4">
                <p class="text-xs uppercase tracking-wider text-fuchsia-200/70">{{ __('ui.recovery.images') }}</p>
                <p class="mt-1 text-2xl font-semibold text-fuchsia-100">{{ number_format($imageCount) }}</p>
            </div>
            <div class="rounded-xl border border-amber-400/20 bg-amber-400/5 p-4">
                <p class="text-xs uppercase tracking-wider text-amber-200/70">{{ __('ui.recovery.videos') }}</p>
                <p class="mt-1 text-2xl font-semibold text-amber-100">{{ number_format($videoCount) }}</p>
            </div>
            <div class="rounded-xl border border-white/10 bg-white/5 p-4">
                <p class="text-xs uppercase tracking-wider text-slate-400">{{ __('ui.recovery.total_size') }}</p>
                <p class="mt-1 text-2xl font-semibold text-slate-100">{{ number_format($totalBytes / 1048576, 1) }} MB</p>
            </div>
        </section>

        @if (empty($items))
            <section class="rounded-2xl border border-dashed border-white/10 bg-white/[0.03] p-10 text-center">
                <h2 class="text-xl font-semibold">{{ __('ui.recovery.empty_title') }}</h2>
                <p class="mt-2 text-sm text-slate-400">{{ __('ui.recovery.empty_help') }}</p>
            </section>
        @else
            <form class="space-y-5" method="POST" action="{{ route('workspace.recovery.update') }}">
                @csrf
                <section class="overflow-hidden rounded-2xl border border-white/10 bg-white/5">
                    <div class="flex flex-col gap-3 border-b border-white/10 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <label class="inline-flex cursor-pointer items-center gap-3 text-sm font-semibold text-slate-200">
                            <input class="h-4 w-4 rounded border-white/20 bg-slate-950 text-red-500" type="checkbox" data-recovery-select-all>
                            {{ __('ui.recovery.select_all') }}
                        </label>
                        <p class="text-sm text-slate-400" aria-live="polite" data-recovery-selection-count data-template="{{ __('ui.recovery.selected_count', ['count' => ':count']) }}">{{ __('ui.recovery.selected_count', ['count' => 0]) }}</p>
                    </div>

                    <div class="divide-y divide-white/10">
                        @foreach ($items as $item)
                            <div class="grid gap-4 p-5 hover:bg-white/[0.03] sm:grid-cols-[auto_5rem_minmax(0,1fr)_auto] sm:items-center">
                                <input class="h-5 w-5 rounded border-white/20 bg-slate-950 text-red-500" id="recovery-item-{{ $loop->index }}" name="items[]" type="checkbox" value="{{ $item['id'] }}" data-recovery-item>

                                @if ($item['type'] === 'image')
                                    <a class="block aspect-square overflow-hidden rounded-lg border border-white/10 bg-slate-950" href="{{ $item['preview_url'] }}" target="_blank" rel="noopener noreferrer" title="{{ __('ui.recovery.preview') }}">
                                        <img class="h-full w-full object-contain" src="{{ $item['preview_url'] }}" alt="{{ $item['original_name'] }}" loading="lazy">
                                    </a>
                                @else
                                    <a class="flex aspect-square items-center justify-center rounded-lg border border-amber-400/20 bg-amber-400/5 text-xs font-bold uppercase tracking-wider text-amber-200" href="{{ $item['preview_url'] }}" target="_blank" rel="noopener noreferrer" title="{{ __('ui.recovery.preview') }}">VIDEO</a>
                                @endif

                                <span class="min-w-0">
                                    <label class="block cursor-pointer truncate font-medium text-slate-100" for="recovery-item-{{ $loop->index }}" title="{{ $item['original_name'] }}">{{ $item['original_name'] }}</label>
                                    <span class="mt-1 block text-xs text-slate-500">{{ $item['scope_label'] }} · {{ $item['type'] === 'image' ? __('ui.recovery.image') : __('ui.recovery.video') }}</span>
                                    <span class="mt-1 block text-xs text-slate-500">{{ number_format($item['size'] / 1048576, 1) }} MB · {{ __('ui.recovery.removed_at') }} {{ \Carbon\CarbonImmutable::createFromTimestamp($item['removed_at'])->translatedFormat(__('ui.formats.video_date')) }}</span>
                                </span>

                                <a class="w-fit rounded-lg border border-white/15 px-3 py-2 text-xs font-semibold text-slate-200" href="{{ $item['preview_url'] }}" target="_blank" rel="noopener noreferrer">{{ __('ui.recovery.preview') }}</a>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="grid gap-4 lg:grid-cols-2">
                    <div class="rounded-2xl border border-emerald-400/20 bg-emerald-400/5 p-5">
                        <h2 class="font-semibold text-emerald-100">{{ __('ui.recovery.restore_title') }}</h2>
                        <p class="mt-2 text-sm text-emerald-100/65">{{ __('ui.recovery.restore_help') }}</p>
                        <button class="mt-4 rounded-lg bg-emerald-300 px-4 py-2 text-sm font-semibold text-slate-950 disabled:cursor-not-allowed disabled:opacity-40" type="submit" name="action" value="restore" data-recovery-action>{{ __('ui.recovery.restore_selected') }}</button>
                    </div>

                    <div class="rounded-2xl border border-red-500/30 bg-red-500/10 p-5">
                        <h2 class="font-semibold text-red-100">{{ __('ui.recovery.purge_title') }}</h2>
                        <p class="mt-2 text-sm text-red-100/70">{{ __('ui.recovery.purge_help') }}</p>
                        <label class="mt-4 block text-xs font-semibold text-red-100" for="purge-confirmation">{{ __('ui.recovery.type_purge_confirmation') }}</label>
                        <input class="mt-2 w-full rounded-lg border border-red-400/30 bg-slate-950 px-3 py-2 font-mono text-sm text-slate-100" id="purge-confirmation" name="purge_confirmation" pattern="delete" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="delete" data-recovery-purge-confirmation>
                        <button class="mt-3 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40" type="submit" name="action" value="purge" data-recovery-action data-recovery-purge-action>{{ __('ui.recovery.purge_selected') }}</button>
                    </div>
                </section>
            </form>
        @endif
    </div>
</x-layouts.app>
