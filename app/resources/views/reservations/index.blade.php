<x-layouts.app :title="__('ui.nav.reservations')">
    <div class="flex items-center justify-between"><h1 class="text-3xl font-semibold">{{ __('ui.reservations.title') }}</h1><a class="rounded bg-amber-400 px-4 py-3 font-semibold text-slate-950" href="{{ route('reservations.create') }}">{{ __('ui.reservations.new') }}</a></div>
    <div class="mt-8 overflow-hidden rounded-xl border border-white/10">
        <table class="w-full text-left text-sm"><thead class="bg-white/5"><tr><th class="p-4">{{ __('ui.compute_nodes.singular') }}</th><th class="p-4">{{ __('ui.common.window') }}</th><th class="p-4">{{ __('ui.common.status') }}</th><th class="p-4">{{ __('ui.common.purpose') }}</th><th class="p-4">{{ __('ui.common.actions') }}</th></tr></thead>
        <tbody>@forelse($reservations as $reservation)
            <tr class="border-t border-white/10">
                <td class="p-4">{{ $reservation->computeNode?->display_name ?? '—' }}</td>
                <td class="p-4">{{ $reservation->starts_at->setTimezone(auth()->user()->timezone)->format('Y-m-d H:i T') }}<br>{{ $reservation->ends_at->setTimezone(auth()->user()->timezone)->format('Y-m-d H:i T') }}</td>
                <td class="p-4">{{ __('ui.statuses.'.$reservation->status->value) }}</td><td class="p-4">{{ $reservation->purpose }}</td>
                <td class="p-4"><div class="space-y-3">@can('extend', $reservation)<x-reservation-extend-control :reservation="$reservation" :options="$extensionOptions->get($reservation->id, [])" />@endcan @can('cancel', $reservation)<form method="POST" action="{{ route('reservations.destroy', $reservation) }}">@csrf @method('DELETE')<button class="text-red-300" type="submit">{{ __('ui.common.cancel') }}</button></form>@endcan</div></td>
            </tr>
        @empty<tr><td class="p-6 text-slate-400" colspan="5">{{ __('ui.reservations.no_reservations') }}</td></tr>@endforelse</tbody></table>
    </div>
    <div class="mt-6">{{ $reservations->links() }}</div>
</x-layouts.app>
