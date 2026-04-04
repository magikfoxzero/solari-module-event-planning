<?php

namespace NewSolari\EventPlanning\Requests;

use NewSolari\Core\Http\Requests\BaseApiFormRequest;

class StoreRecurringPatternRequest extends BaseApiFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'recurrence_type' => 'required|string|in:none,daily,weekly,monthly,yearly,custom',
            'interval_value' => 'integer|min:1|max:365',
            'days_of_week' => 'nullable|array',
            'days_of_week.*' => 'integer|min:0|max:6',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'week_of_month' => 'nullable|integer|min:1|max:5',
            'month_of_year' => 'nullable|integer|min:1|max:12',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'max_occurrences' => 'nullable|integer|min:1|max:365',
            'event_template' => 'nullable|array',
            'event_template.title' => 'nullable|string|max:255',
            'event_template.description' => 'nullable|string',
            'event_template.start_time' => 'nullable|date_format:H:i',
            'event_template.end_time' => 'nullable|date_format:H:i',
            'event_template.duration_hours' => 'nullable|numeric|min:0.5|max:24',
            'event_template.event_type' => 'nullable|string|max:50',
            'event_template.location' => 'nullable|string|max:255',
            'event_template.color' => 'nullable|string|max:7',
            'event_template.tags' => 'nullable|string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'recurrence_type.in' => 'Recurrence type must be one of: none, daily, weekly, monthly, yearly, custom',
            'days_of_week.*.min' => 'Day of week must be between 0 (Sunday) and 6 (Saturday)',
            'days_of_week.*.max' => 'Day of week must be between 0 (Sunday) and 6 (Saturday)',
            'end_date.after_or_equal' => 'End date must be on or after the start date',
        ];
    }
}
