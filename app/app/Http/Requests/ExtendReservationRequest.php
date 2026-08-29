<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExtendReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['ends_at' => ['required', 'string', 'regex:/(?:Z|[+-]\d{2}:\d{2})$/', 'date']];
    }
}
