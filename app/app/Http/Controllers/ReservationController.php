<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExtendReservationRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Models\ComputeNode;
use App\Models\Reservation;
use App\Services\ComputeNodeStatusService;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationService;
use App\Services\WorkspaceRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ReservationAvailabilityService $availability,
        private readonly WorkspaceRuntimeService $workspaces,
        private readonly ComputeNodeStatusService $nodes,
    ) {}

    public function index(Request $request): View
    {
        $timezone = $request->user()->timezone ?: config('movie.display_timezone');
        $reservations = $request->user()->reservations()->with('computeNode')->latest('starts_at')->paginate(20);
        $extensionOptions = $reservations->getCollection()
            ->mapWithKeys(fn (Reservation $reservation): array => [
                $reservation->id => $this->availability->extensionOptions($reservation, $timezone),
            ]);

        return view('reservations.index', compact('reservations', 'extensionOptions'));
    }

    public function create(Request $request): View
    {
        $timezone = $request->user()->timezone ?: config('movie.display_timezone');
        $dateOptions = $this->availability->dateOptions($timezone);
        $oldStartsAt = old('starts_at');
        $selectedDate = $dateOptions[0]['value'];

        if (is_string($oldStartsAt) && $oldStartsAt !== '') {
            try {
                $selectedDate = CarbonImmutable::parse($oldStartsAt)->setTimezone($timezone)->format('Y-m-d');
            } catch (\Throwable) {
                // Keep the first bookable date when stale input cannot be parsed.
            }
        }

        return view('reservations.create', [
            'availabilityUrl' => route('reservations.availability'),
            'nodesUrl' => route('reservations.nodes'),
            'nodes' => $this->nodes->publicNodes(),
            'selectedNodeId' => old('compute_node_id'),
            'dateOptions' => $dateOptions,
            'selectedDate' => $selectedDate,
            'timezone' => $timezone,
        ]);
    }

    public function nodes(): JsonResponse
    {
        $response = response()->json([
            'generated_at' => now()->toIso8601String(),
            'nodes' => $this->nodes->publicNodes(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'compute_node_id' => ['required', 'uuid', 'exists:compute_nodes,id'],
        ]);

        $node = ComputeNode::query()->visibleInReservations()->findOrFail($validated['compute_node_id']);
        $this->nodes->assertAcceptsReservations($node);

        $response = response()->json($this->availability->forDate(
            $validated['date'],
            $request->user()->timezone ?: config('movie.display_timezone'),
            node: $node,
        ));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function store(StoreReservationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $immediate = $request->boolean('start_immediately');
        $startsAt = $immediate
            ? $this->availability->immediateStart()
            : CarbonImmutable::parse($data['starts_at']);
        $node = ComputeNode::query()->visibleInReservations()->findOrFail($data['compute_node_id']);

        $this->reservations->create(
            $request->user(),
            $startsAt,
            CarbonImmutable::parse($data['ends_at']),
            $data['purpose'] ?? null,
            $immediate,
            $node,
        );

        return redirect()->route('reservations.index')->with('status', __('ui.messages.reservation_created'));
    }

    public function destroy(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->authorize('cancel', $reservation);
        $this->reservations->cancel($reservation, $request->user());

        return back()->with('status', __('ui.messages.reservation_cancelled'));
    }

    public function extend(ExtendReservationRequest $request, Reservation $reservation): RedirectResponse
    {
        $this->authorize('extend', $reservation);
        $extended = $this->reservations->extend($reservation, CarbonImmutable::parse($request->validated('ends_at')));
        $this->workspaces->syncExtendedDeadline($extended);

        return back()->with('status', __('ui.messages.reservation_extended'));
    }
}
