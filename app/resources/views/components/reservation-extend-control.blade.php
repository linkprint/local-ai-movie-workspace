@props(['reservation', 'options' => []])

<details class="relative text-left" data-testid="reservation-extend-control">
    <summary class="workspace-action inline-flex cursor-pointer list-none rounded-lg border border-emerald-300/30 px-3 py-1.5 text-xs font-semibold text-emerald-200 transition hover:border-emerald-200 hover:text-emerald-100">
        {{ __('ui.reservations.extend') }}
    </summary>
    <div class="mt-2 w-80 max-w-[80vw] rounded-xl border border-emerald-300/20 bg-slate-950 p-4 shadow-2xl">
        <p class="text-xs leading-5 text-slate-400">{{ __('ui.reservations.extend_help') }}</p>
        @if ($options !== [])
            <form class="mt-3 space-y-3" method="POST" action="{{ route('reservations.extend', $reservation) }}">
                @csrf
                <label class="block text-xs font-semibold text-slate-300" for="extend-ends-at-{{ $reservation->id }}">{{ __('ui.reservations.extend_to') }}</label>
                <select class="w-full rounded-lg border border-white/15 bg-slate-900 px-3 py-2 text-sm text-white" id="extend-ends-at-{{ $reservation->id }}" name="ends_at" required>
                    @foreach ($options as $option)
                        <option value="{{ $option['value'] }}">{{ __('ui.reservations.extend_option', ['time' => $option['label'], 'added' => $option['added_duration'], 'total' => $option['total_duration']]) }}</option>
                    @endforeach
                </select>
                <button class="workspace-action w-full rounded-lg bg-emerald-300 px-3 py-2 text-sm font-semibold text-slate-950" type="submit">{{ __('ui.reservations.extend_confirm') }}</button>
            </form>
        @else
            <p class="mt-3 text-xs leading-5 text-amber-200">{{ __('ui.reservations.extend_unavailable') }}</p>
        @endif
    </div>
</details>
