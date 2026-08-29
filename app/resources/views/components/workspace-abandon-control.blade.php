@props(['reservation'])

<details class="workspace-action rounded-lg border border-red-500/40 bg-red-500/10 px-3 py-2 text-left">
    <summary class="cursor-pointer text-xs font-semibold text-red-200">{{ __('ui.workspace.abandon') }}</summary>
    <p class="mt-2 max-w-md text-xs leading-5 text-slate-300">
        {{ __('ui.workspace.abandon_help') }}
    </p>
    <form class="mt-3" method="POST" action="{{ route('workspace.reservations.abandon', $reservation) }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="abandon_confirmation" value="abandon">
        <button class="workspace-action rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-500" type="submit">{{ __('ui.workspace.confirm_abandon') }}</button>
    </form>
</details>
