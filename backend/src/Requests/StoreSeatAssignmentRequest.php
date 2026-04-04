<?php

namespace NewSolari\EventPlanning\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSeatAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seat_table_id' => 'required|string|max:36',
            'person_id' => 'nullable|string|max:36',
            'guest_name' => 'nullable|string|max:255|required_without:person_id',
            'guest_email' => 'nullable|email|max:255',
            'seat_number' => 'required|integer|min:1',
            'dietary_requirements' => 'nullable|string',
            'accessibility_needs' => 'nullable|string',
            'notes' => 'nullable|string',
            'rsvp_status' => 'nullable|string|in:pending,confirmed,declined,tentative',
        ];
    }

    public function messages(): array
    {
        return [
            'guest_name.required_without' => 'Either a person must be selected or a guest name must be provided.',
        ];
    }
}
