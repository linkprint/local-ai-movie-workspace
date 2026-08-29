<?php

namespace App\Filament\Resources\ComputeNodes\Pages;

use App\Enums\ComputeNodeSchedulingState;
use App\Enums\ReservationStatus;
use App\Filament\Resources\ComputeNodes\ComputeNodeResource;
use App\Models\AuditEvent;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditComputeNode extends EditRecord
{
    protected static string $resource = ComputeNodeResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['host_ip'] ?? null) === $this->record->host_ip) {
            return $data;
        }

        $hasReservations = $this->record->reservations()
            ->whereIn('status', [
                ReservationStatus::Confirmed,
                ReservationStatus::Provisioning,
                ReservationStatus::Active,
                ReservationStatus::Ending,
            ])
            ->where('ends_at', '>', now())
            ->exists();

        if ($hasReservations) {
            throw ValidationException::withMessages([
                'data.host_ip' => __('ui.compute_nodes.errors.ip_has_reservations'),
            ]);
        }

        $data['scheduling_state'] = ComputeNodeSchedulingState::Maintenance->value;
        $data['last_heartbeat_at'] = null;
        $data['last_health_summary'] = ['ok' => false, 'error' => 'ip_changed'];
        $data['last_error_code'] = 'ip_changed';

        return $data;
    }

    protected function afterSave(): void
    {
        AuditEvent::record('compute_node.updated', $this->record, [
            'display_name' => $this->record->display_name,
            'visible_in_reservations' => $this->record->visible_in_reservations,
            'scheduling_state' => $this->record->scheduling_state->value,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
