<?php

namespace NewSolari\EventPlanning\Requests;

use NewSolari\Core\Http\Requests\BaseApiFormRequest;

class StoreConnectionRequest extends BaseApiFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'from_node_id' => 'required|string|max:36|exists:event_plan_nodes,record_id',
            'to_node_id' => 'required|string|max:36|exists:event_plan_nodes,record_id|different:from_node_id',
            'from_side' => 'sometimes|in:top,right,bottom,left',
            'to_side' => 'sometimes|in:top,right,bottom,left',
            'style' => 'sometimes|in:solid,dashed,dotted',
            'path_type' => 'sometimes|in:curved,straight,orthogonal',
            'color' => 'sometimes|string|max:7',
            'thickness' => 'sometimes|numeric|min:0.5|max:10',
            'arrow_type' => 'sometimes|in:none,forward,backward,both',
            'relationship_type' => 'nullable|string|max:64',
            'relationship_label' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ];
    }
}
