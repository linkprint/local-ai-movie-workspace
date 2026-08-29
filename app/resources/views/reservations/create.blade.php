<x-layouts.app :title="__('ui.reservations.new')">
    <form
        class="mx-auto max-w-5xl"
        method="POST"
        action="{{ route('reservations.store') }}"
        data-reservation-picker
        data-availability-url="{{ $availabilityUrl }}"
        data-nodes-url="{{ $nodesUrl }}"
        data-initial-nodes="{{ base64_encode(json_encode($nodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}"
        data-old-node="{{ $selectedNodeId }}"
        data-old-start="{{ old('starts_at') }}"
        data-old-end="{{ old('ends_at') }}"
    >
        @csrf
        <input name="compute_node_id" type="hidden" value="{{ $selectedNodeId }}" data-node-value>
        <input name="starts_at" type="hidden" value="{{ old('starts_at') }}" data-start-value>
        <input name="start_immediately" type="hidden" value="0" data-start-immediately>
        <input name="ends_at" type="hidden" value="{{ old('ends_at') }}" data-end-value>

        <div class="mb-8 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-medium uppercase tracking-[0.2em] text-amber-300">{{ __('ui.reservations.exclusive_time') }}</p>
                <h1 class="mt-2 text-3xl font-semibold">{{ __('ui.reservations.new') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">{{ __('ui.reservations.intro', ['timezone' => $timezone]) }}</p>
            </div>
            <a class="text-sm text-slate-300 underline decoration-white/20 underline-offset-4 hover:text-white" href="{{ route('reservations.index') }}">{{ __('ui.reservations.view_mine') }}</a>
        </div>

        <section class="mb-6 rounded-2xl border border-white/10 bg-white/[0.04] p-6 shadow-2xl shadow-black/20 sm:p-8" aria-labelledby="server-heading">
            <p class="text-sm font-medium text-amber-300">{{ __('ui.reservations.step_1') }}</p>
            <h2 class="mt-1 text-xl font-semibold" id="server-heading">{{ __('ui.compute_nodes.select_server') }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-400">{{ __('ui.compute_nodes.select_help') }}</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-node-list>
                @foreach($nodes as $node)
                    <button
                        class="group rounded-2xl border p-5 text-left transition {{ $node['selectable'] ? 'border-white/10 bg-slate-950/50 hover:border-amber-300/50' : 'cursor-not-allowed border-red-400/20 bg-red-950/10 opacity-75' }}"
                        type="button"
                        data-node-card
                        data-node-id="{{ $node['id'] }}"
                        @disabled(! $node['selectable'])
                    >
                        <span class="flex items-start justify-between gap-4">
                            <span class="flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-slate-200" aria-hidden="true">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="3.5" width="19" height="13" rx="2"/><path d="M8 20.5h8M12 16.5v4M6 13.5h12"/></svg>
                            </span>
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $node['availability_state'] === 'idle' ? 'bg-emerald-400/10 text-emerald-200' : ($node['availability_state'] === 'busy' ? 'bg-amber-400/10 text-amber-200' : 'bg-red-400/10 text-red-200') }}">{{ $node['state_label'] }}</span>
                        </span>
                        <span class="mt-4 block font-semibold text-white">{{ $node['display_name'] }}</span>
                        @if($node['capability_labels'] !== [])
                            <span class="mt-2 block text-xs text-slate-500">{{ implode(' · ', $node['capability_labels']) }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </section>

        <div class="grid overflow-hidden rounded-2xl border border-white/10 bg-white/[0.04] shadow-2xl shadow-black/20 lg:grid-cols-[minmax(0,1.15fr)_minmax(300px,0.85fr)]">
            <section class="space-y-6 p-6 sm:p-8" aria-labelledby="window-heading">
                <div>
                    <p class="text-sm font-medium text-amber-300">{{ __('ui.reservations.step_2') }}</p>
                    <h2 class="mt-1 text-xl font-semibold" id="window-heading">{{ __('ui.reservations.select_window') }}</h2>
                </div>

                <div class="grid gap-5 sm:grid-cols-3">
                    <label class="block text-sm font-medium text-slate-200" for="reservation-date">
                        {{ __('ui.reservations.date') }}
                        <select class="mt-2 w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-3 text-white outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-400/20 disabled:cursor-not-allowed disabled:opacity-50" id="reservation-date" data-date-select disabled>
                            @foreach($dateOptions as $option)
                                <option value="{{ $option['value'] }}" @selected($option['value'] === $selectedDate)>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block text-sm font-medium text-slate-200" for="reservation-start-time">
                        {{ __('ui.reservations.start_time') }}
                        <select class="mt-2 w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-3 text-white outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-400/20 disabled:cursor-not-allowed disabled:opacity-50" id="reservation-start-time" data-start-select disabled>
                            <option value="">{{ __('ui.reservations.loading') }}</option>
                        </select>
                    </label>

                    <label class="block text-sm font-medium text-slate-200" for="reservation-end-time">
                        {{ __('ui.reservations.end_time') }}
                        <select class="mt-2 w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-3 text-white outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-400/20 disabled:cursor-not-allowed disabled:opacity-50" id="reservation-end-time" data-end-select disabled>
                            <option value="">{{ __('ui.reservations.select_start') }}</option>
                        </select>
                    </label>
                </div>

                <div class="rounded-xl border border-white/10 bg-slate-950/50 p-4" aria-live="polite" data-selection-summary>
                    <p class="text-sm text-slate-400">{{ __('ui.compute_nodes.select_first') }}</p>
                </div>

                <label class="block text-sm font-medium text-slate-200" for="reservation-purpose">
                    {{ __('ui.common.purpose') }} <span class="font-normal text-slate-500">({{ __('ui.common.optional') }})</span>
                    <textarea class="mt-2 w-full rounded-xl border border-white/10 bg-slate-900 p-3 text-white outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-400/20" id="reservation-purpose" name="purpose" rows="4" placeholder="{{ __('ui.reservations.purpose_placeholder') }}">{{ old('purpose') }}</textarea>
                </label>

                <div class="flex flex-col gap-3 border-t border-white/10 pt-6 sm:flex-row sm:items-center sm:justify-between">
                    <p class="max-w-xl text-xs leading-5 text-slate-500">{{ __('ui.reservations.timing_help') }}</p>
                    <button class="w-[120px] shrink-0 rounded-xl bg-amber-400 px-5 py-3 font-semibold text-slate-950 transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-40" type="submit" data-reserve-button disabled>{{ __('ui.reservations.reserve') }}</button>
                </div>
            </section>

            <aside class="border-t border-white/10 bg-slate-950/45 p-6 sm:p-8 lg:border-l lg:border-t-0" aria-labelledby="availability-heading">
                <p class="text-sm font-medium text-amber-300">{{ __('ui.reservations.live_availability') }}</p>
                <h2 class="mt-1 text-xl font-semibold" id="availability-heading" data-availability-heading>{{ __('ui.reservations.available_windows') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">{{ __('ui.reservations.availability_help') }}</p>

                <div class="mt-6 space-y-3" data-availability-windows>
                    <div class="animate-pulse rounded-xl border border-white/10 bg-white/5 p-4">
                        <div class="h-4 w-32 rounded bg-white/10"></div>
                        <div class="mt-3 h-3 w-44 rounded bg-white/10"></div>
                    </div>
                </div>

                <div class="mt-6 rounded-xl border border-sky-400/20 bg-sky-950/30 p-4 text-xs leading-5 text-sky-100/80">
                    {{ __('ui.reservations.availability_warning') }}
                </div>
            </aside>
        </div>

        <noscript><p class="mt-4 rounded-lg border border-red-500/40 bg-red-950/60 p-4 text-red-200">{{ __('ui.reservations.javascript_required') }}</p></noscript>
    </form>
</x-layouts.app>
