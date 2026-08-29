<article class="rounded-xl border border-white/10 bg-slate-950/70 p-4">
    <label class="mb-4 flex cursor-pointer items-center gap-3 border-b border-white/10 pb-3 text-xs font-semibold text-slate-300">
        <input class="h-5 w-5 rounded border-white/20 bg-slate-950 text-red-500" form="{{ $bulkFormId }}" name="items[]" type="checkbox" value="{{ $video['selection_id'] }}" data-media-item>
        <span class="truncate">{{ __('ui.videos.select_media', ['name' => $video['name']]) }}</span>
    </label>
    <div class="min-w-0">
        <p class="truncate font-medium text-slate-100" title="{{ $video['path'] }}">{{ $video['name'] }}</p>
        <p class="mt-1 truncate font-mono text-xs text-slate-500" title="{{ $video['path'] }}">{{ $video['path'] }}</p>
        <p class="mt-2 text-xs text-slate-500">{{ number_format($video['size'] / 1048576, 1) }} MB · {{ \Carbon\CarbonImmutable::createFromTimestamp($video['modified_at'])->translatedFormat(__('ui.formats.video_date')) }}</p>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        <a class="rounded-lg bg-amber-300 px-3 py-2 text-xs font-semibold text-slate-950" href="{{ $video['url'] }}" target="_blank" rel="noopener noreferrer">{{ __('ui.videos.open_new_tab') }}</a>
        <a class="rounded-lg border border-white/15 px-3 py-2 text-xs font-semibold text-slate-200" href="{{ $video['download_url'] }}">{{ __('ui.videos.download') }}</a>
    </div>
    <details class="mt-4 border-t border-white/10 pt-3">
        <summary class="cursor-pointer text-xs font-medium text-slate-400">{{ __('ui.videos.rename_delete') }}</summary>
        <form class="mt-3 flex flex-col gap-2 sm:flex-row" method="POST" action="{{ $video['url'] }}">
            @csrf
            @method('PATCH')
            <input class="min-w-0 flex-1 rounded-lg border border-white/15 bg-slate-950 px-3 py-2 text-sm text-slate-100" name="new_name" maxlength="200" required value="{{ $video['name'] }}" aria-label="{{ __('ui.videos.new_filename') }}">
            <button class="rounded-lg border border-amber-300/35 px-3 py-2 text-xs font-semibold text-amber-200" type="submit">{{ __('ui.videos.rename') }}</button>
        </form>
        <form class="mt-3 flex items-center justify-between gap-3 rounded-lg border border-red-400/20 bg-red-400/5 p-3" method="POST" action="{{ $video['url'] }}">
            @csrf
            @method('DELETE')
            <input name="delete_confirmation" type="hidden" value="delete">
            <p class="text-xs text-slate-400">{{ __('ui.videos.trash_help') }}</p>
            <button class="shrink-0 rounded-lg bg-red-500 px-3 py-2 text-xs font-semibold text-white" type="submit">{{ __('ui.videos.delete') }}</button>
        </form>
    </details>
</article>
