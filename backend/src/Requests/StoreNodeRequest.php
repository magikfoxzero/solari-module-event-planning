<?php

namespace NewSolari\EventPlanning\Requests;

use NewSolari\Core\Http\Requests\BaseApiFormRequest;

class StoreNodeRequest extends BaseApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'entity_type' => 'required|string|in:person,place,event,note,task,file,entity,inventory_object,budget,invoice,tag,hypothesis,motive',
            'entity_id' => 'required|string|max:36',
            'x' => 'sometimes|numeric',
            'y' => 'sometimes|numeric',
            'width' => 'sometimes|numeric|min:50',
            'height' => 'sometimes|numeric|min:30',
            'z_index' => 'sometimes|integer',
            'style' => 'nullable|array',
            'label_override' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'is_pinned' => 'sometimes|boolean',
            'is_collapsed' => 'sometimes|boolean',
        ];
    }
}
