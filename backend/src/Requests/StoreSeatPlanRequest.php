<?php

namespace NewSolari\EventPlanning\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSeatPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'layout_type' => 'nullable|string|in:banquet,theater,classroom,conference,custom',
            'canvas_state' => 'nullable|array',
            'floor_plan_image_id' => 'nullable|string|max:36',
        ];
    }
}
