<x-layouts.app :title="__('ui.images.title')">
    @vite('resources/js/media-library.js')
    @php($bulkFormId = 'image-library-bulk-trash')
    <div class="space-y-6" data-media-library data-media-async-trash data-request-error="{{ __('ui.images.trash_request_failed') }}">
        <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-sm font-medium uppercase tracking-[0.22em] text-fuchsia-300">{{ __('ui.images.persistent_media') }}</p>
                <h1 class="mt-2 text-3xl font-semibold">{{ __('ui.images.title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-400">{{ __('ui.images.intro') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="inline-flex w-fit rounded-lg border border-red-400/30 bg-red-400/10 px-4 py-2 text-sm font-semibold text-red-100" href="{{ route('workspace.recovery.index') }}">{{ __('ui.images.open_recovery') }}</a>
                <a class="inline-flex w-fit rounded-lg border border-white/15 px-4 py-2 text-sm text-slate-300" href="{{ route('workspace') }}">{{ __('ui.images.back_projects') }}</a>
            </div>
        </div>

        @if ($totalImages > 0)
            <section class="flex flex-col gap-4 rounded-2xl border border-red-400/25 bg-red-400/5 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <label class="inline-flex cursor-pointer items-center gap-3 text-sm font-semibold text-slate-100">
                        <input class="h-5 w-5 rounded border-white/20 bg-slate-950 text-red-500" type="checkbox" data-media-select-all>
                        {{ __('ui.images.select_all') }}
                    </label>
                    <p class="mt-2 text-sm text-slate-400" aria-live="polite" data-media-selection-count data-template="{{ __('ui.images.selected_count', ['count' => ':count']) }}">{{ __('ui.images.selected_count', ['count' => 0]) }}</p>
                </div>
                <form id="{{ $bulkFormId }}" method="POST" action="{{ route('workspace.images.bulk-trash') }}" data-media-bulk-form data-confirm="{{ __('ui.images.bulk_trash_confirm') }}">
                    @csrf
                    <input name="bulk_confirmation" type="hidden" value="move to recovery">
                    <button class="rounded-lg bg-red-500 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40" type="submit" disabled data-media-bulk-action>{{ __('ui.images.bulk_trash') }}</button>
                </form>
            </section>
        @endif

        @forelse ($projects as $entry)
            <section class="rounded-2xl border border-white/10 bg-white/5 p-6" data-media-section>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold">{{ $entry['project']->name }}</h2>
                        <p class="mt-1 font-mono text-xs text-slate-500">{{ __('ui.common.project') }} · {{ $entry['project']->directory_name }}</p>
                    </div>
                    <span class="rounded-full border border-fuchsia-400/25 bg-fuchsia-400/10 px-3 py-1 text-xs font-semibold text-fuchsia-200" data-media-section-count data-template="{{ __('ui.images.count', ['count' => ':count']) }}">{{ __('ui.images.count', ['count' => count($entry['images'])]) }}</span>
                </div>
                @if (empty($entry['images']))
                    <p class="mt-5 rounded-xl border border-dashed border-white/10 px-4 py-6 text-center text-sm text-slate-500">{{ __('ui.images.none_project') }}</p>
                @else
                    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($entry['images'] as $image)
                            @include('partials.workspace-image-card', ['image' => $image])
                        @endforeach
                    </div>
                @endif
            </section>
        @empty
            <section class="rounded-2xl border border-white/10 bg-white/5 p-8 text-center">
                <h2 class="text-xl font-semibold">{{ __('ui.images.create_first') }}</h2>
                <p class="mt-2 text-sm text-slate-400">{{ __('ui.images.saved_under_project') }}</p>
                <a class="mt-5 inline-flex rounded-lg bg-amber-300 px-4 py-2 text-sm font-semibold text-slate-950" href="{{ route('workspace') }}">{{ __('ui.images.create_project') }}</a>
            </section>
        @endforelse

        @if (! empty($legacyImages))
            <section class="rounded-2xl border border-amber-300/20 bg-amber-300/5 p-6" data-media-section>
                <h2 class="text-xl font-semibold text-amber-100">{{ __('ui.images.legacy_title') }}</h2>
                <p class="mt-2 text-sm text-amber-100/65">{{ __('ui.images.legacy_help') }}</p>
                <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($legacyImages as $image)
                        @include('partials.workspace-image-card', ['image' => $image])
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.app>
