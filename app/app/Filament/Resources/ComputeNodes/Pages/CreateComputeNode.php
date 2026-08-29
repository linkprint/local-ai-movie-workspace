<?php

namespace App\Filament\Resources\ComputeNodes\Pages;

use App\Enums\ComputeNodeSchedulingState;
use App\Filament\Resources\ComputeNodes\ComputeNodeResource;
use App\Models\AuditEvent;
use App\Services\ComputeNodeRegistry;
use Filament\Resources\Pages\CreateRecord;

class CreateComputeNode extends CreateRecord
{
    protected static string $resource = ComputeNodeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['slug'] = app(ComputeNodeRegistry::class)->newSlug();
        $data['visible_in_reservations'] = true;
        $data['scheduling_state'] = ComputeNodeSchedulingState::Maintenance->value;
        $data['last_health_summary'] = ['ok' => false, 'error' => 'not_verified'];
        $data['last_error_code'] = 'not_verified';

        return $data;
    }

    protected function afterCreate(): void
    {
        AuditEvent::record('compute_node.registered', $this->record, [
            'display_name' => $this->record->display_name,
        ]);
    }
}
