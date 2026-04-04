<?php

namespace NewSolari\EventPlanning\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSeatTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:100',
            'table_number' => 'nullable|integer|min:1',
            'table_type' => 'nullable|string|in:round,rectangular,square,custom',
            'capacity' => 'nullable|integer|min:1|max:50',
            'x' => 'nullable|numeric',
            'y' => 'nullable|numeric',
            'rotation' => 'nullable|numeric|min:0|max:360',
            'width' => 'nullable|numeric|min:20',
            'height' => 'nullable|numeric|min:20',
            'color' => 'nullable|string|max:7',
            'notes' => 'nullable|string',
        ];
    }
}
