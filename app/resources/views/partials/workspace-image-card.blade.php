<article class="overflow-hidden rounded-xl border border-white/10 bg-slate-950/70" data-media-card>
    <label class="flex cursor-pointer items-center gap-3 border-b border-white/10 px-4 py-3 text-xs font-semibold text-slate-300">
        <input class="h-5 w-5 rounded border-white/20 bg-slate-950 text-red-500" form="{{ $bulkFormId }}" name="items[]" type="checkbox" value="{{ $image['selection_id'] }}" data-media-item>
        <span class="truncate">{{ __('ui.images.select_media', ['name' => $image['name']]) }}</span>
    </label>
    <a class="block aspect-square bg-slate-900" href="{{ $image['url'] }}" target="_blank" rel="noopener noreferrer" title="{{ __('ui.images.open_new_tab') }}">
        <img class="h-full w-full object-contain" src="{{ $image['url'] }}" alt="{{ $image['name'] }}" loading="lazy">
    </a>
    <div class="p-4">
        <p class="truncate font-medium text-slate-100" title="{{ $image['path'] }}">{{ $image['name'] }}</p>
        <p class="mt-1 truncate font-mono text-xs text-slate-500" title="{{ $image['path'] }}">{{ $image['path'] }}</p>
        <p class="mt-2 text-xs text-slate-500">{{ number_format($image['size'] / 1048576, 1) }} MB · {{ \Carbon\CarbonImmutable::createFromTimestamp($image['modified_at'])->translatedFormat(__('ui.formats.image_date')) }}</p>
        <div class="mt-4 flex flex-wrap gap-2">
            <a class="rounded-lg bg-fuchsia-300 px-3 py-2 text-xs font-semibold text-slate-950" href="{{ $image['url'] }}" target="_blank" rel="noopener noreferrer">{{ __('ui.images.open_new_tab') }}</a>
            <a class="rounded-lg border border-white/15 px-3 py-2 text-xs font-semibold text-slate-200" href="{{ $image['download_url'] }}">{{ __('ui.images.download') }}</a>
        </div>
        <details class="mt-4 border-t border-white/10 pt-3">
            <summary class="cursor-pointer text-xs font-medium text-slate-400">{{ __('ui.images.rename_delete') }}</summary>
            <form class="mt-3 flex flex-col gap-2" method="POST" action="{{ $image['url'] }}">
                @csrf
                @method('PATCH')
                <input class="min-w-0 flex-1 rounded-lg border border-white/15 bg-slate-950 px-3 py-2 text-sm text-slate-100" name="new_name" maxlength="200" required value="{{ $image['name'] }}" aria-label="{{ __('ui.images.new_filename') }}">
                <button class="rounded-lg border border-fuchsia-300/35 px-3 py-2 text-xs font-semibold text-fuchsia-200" type="submit">{{ __('ui.images.rename') }}</button>
            </form>
            <form class="mt-3 flex items-center justify-between gap-3 rounded-lg border border-red-400/20 bg-red-400/5 p-3" method="POST" action="{{ $image['url'] }}" data-media-delete-form>
                @csrf
                @method('DELETE')
                <input name="delete_confirmation" type="hidden" value="delete">
                <p class="text-xs text-slate-400">{{ __('ui.images.trash_help') }}</p>
                <button class="shrink-0 rounded-lg bg-red-500 px-3 py-2 text-xs font-semibold text-white" type="submit">{{ __('ui.images.delete') }}</button>
            </form>
        </details>
    </div>
</article>
