<?php

namespace NewSolari\EventPlanning\Requests;

use NewSolari\Core\Http\Requests\BaseApiFormRequest;

class StoreEventPlanRequest extends BaseApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'event_type' => 'nullable|string|max:50',
            'status' => 'sometimes|in:draft,planning,active,on_hold,completed,cancelled',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'timezone' => 'nullable|string|max:50',
            'venue_place_id' => 'nullable|string|max:36',
            'organizer_entity_id' => 'nullable|string|max:36',
            'expected_guests' => 'nullable|integer|min:0',
            'budget_id' => 'nullable|string|exists:budgets,record_id',
            'default_view' => 'sometimes|in:calendar,seats,canvas',
            'is_public' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'An event plan title is required.',
            'end_date.after_or_equal' => 'End date must be on or after start date.',
        ];
    }
}
