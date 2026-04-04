<?php

namespace NewSolari\EventPlanning\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSeatAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seat_table_id' => 'sometimes|string|max:36',
            'person_id' => 'nullable|string|max:36',
            'guest_name' => 'nullable|string|max:255',
            'guest_email' => 'nullable|email|max:255',
            'seat_number' => 'sometimes|integer|min:1',
            'dietary_requirements' => 'nullable|string',
            'accessibility_needs' => 'nullable|string',
            'notes' => 'nullable|string',
            'rsvp_status' => 'nullable|string|in:pending,confirmed,declined,tentative',
        ];
    }
}
