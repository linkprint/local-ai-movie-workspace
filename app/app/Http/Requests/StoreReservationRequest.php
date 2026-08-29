<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $offset = '/(?:Z|[+-]\d{2}:\d{2})$/';

        return [
            'compute_node_id' => ['required', 'uuid', 'exists:compute_nodes,id,visible_in_reservations,1'],
            'starts_at' => ['required', 'string', 'regex:'.$offset, 'date'],
            'start_immediately' => ['sometimes', 'boolean'],
            'ends_at' => ['required', 'string', 'regex:'.$offset, 'date'],
            'purpose' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
