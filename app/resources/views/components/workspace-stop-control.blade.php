@props(['reservation' => null])

<details class="workspace-action rounded-lg border border-red-400/25 bg-red-400/5 px-3 py-2 text-left">
    <summary class="cursor-pointer text-xs font-semibold text-red-200">{{ __('ui.workspace.stop') }}</summary>
    <p class="mt-2 max-w-md text-xs leading-5 text-slate-400">
        @if ($reservation)
            {{ __('ui.workspace.stop_help', ['time' => $reservation->ends_at->copy()->setTimezone(auth()->user()->timezone)->translatedFormat(__('ui.formats.date_time'))]) }}
        @else
            {{ __('ui.workspace.stop_help_runtime') }}
        @endif
    </p>
    <form class="mt-3" method="POST" action="{{ route('workspace.stop') }}">
        @csrf
        <input type="hidden" name="stop_confirmation" value="stop">
        <button class="workspace-action rounded-lg bg-red-500 px-3 py-2 text-xs font-semibold text-white hover:bg-red-400" type="submit">{{ __('ui.workspace.confirm_stop') }}</button>
    </form>
</details>
