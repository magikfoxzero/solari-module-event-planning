<?php

namespace NewSolari\EventPlanning\Models;

use NewSolari\Core\Entity\BaseEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPlanConnection extends BaseEntity
{
    protected $table = 'event_plan_connections';
    protected $primaryKey = 'record_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'record_id',
        'partition_id',
        'event_plan_id',
        'from_node_id',
        'to_node_id',
        'from_side',
        'to_side',
        'style',
        'path_type',
        'color',
        'thickness',
        'arrow_type',
        'relationship_type',
        'relationship_label',
        'notes',
    ];

    protected $casts = [
        'thickness' => 'decimal:1',
    ];

    protected $validations = [
        'record_id' => 'nullable|string|max:36',
        'partition_id' => 'sometimes|string|max:36|exists:identity_partitions,record_id',
        'event_plan_id' => 'required|string|max:36|exists:event_plans,record_id',
        'from_node_id' => 'required|string|max:36|exists:event_plan_nodes,record_id',
        'to_node_id' => 'required|string|max:36|exists:event_plan_nodes,record_id',
        'from_side' => 'nullable|string|in:top,right,bottom,left',
        'to_side' => 'nullable|string|in:top,right,bottom,left',
        'style' => 'nullable|string|in:solid,dashed,dotted',
        'path_type' => 'nullable|string|in:curved,straight,orthogonal',
        'color' => 'nullable|string|max:20',
        'thickness' => 'nullable|numeric|min:0.5|max:10',
        'arrow_type' => 'nullable|string|in:none,forward,backward,both',
        'relationship_type' => 'nullable|string|max:50',
        'relationship_label' => 'nullable|string|max:255',
        'notes' => 'nullable|string',
    ];

    protected $appends = ['visual_properties'];

    /**
     * Line styles
     */
    public const STYLES = [
        'solid' => 'Solid',
        'dashed' => 'Dashed',
        'dotted' => 'Dotted',
    ];

    /**
     * Path types
     */
    public const PATH_TYPES = [
        'curved' => 'Curved',
        'straight' => 'Straight',
        'orthogonal' => 'Orthogonal',
    ];

    /**
     * Arrow types
     */
    public const ARROW_TYPES = [
        'none' => 'None',
        'forward' => 'Forward',
        'backward' => 'Backward',
        'both' => 'Both',
    ];

    /**
     * Anchor sides
     */
    public const SIDES = ['top', 'right', 'bottom', 'left'];

    /**
     * Get the parent event plan
     */
    public function eventPlan(): BelongsTo
    {
        return $this->belongsTo(EventPlan::class, 'event_plan_id', 'record_id');
    }

    /**
     * Get source node
     */
    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(EventPlanNode::class, 'from_node_id', 'record_id');
    }

    /**
     * Get target node
     */
    public function toNode(): BelongsTo
    {
        return $this->belongsTo(EventPlanNode::class, 'to_node_id', 'record_id');
    }

    /**
     * Get visual properties attribute
     */
    public function getVisualPropertiesAttribute(): array
    {
        return [
            'style' => $this->style,
            'path_type' => $this->path_type,
            'color' => $this->color,
            'thickness' => (float) $this->thickness,
            'arrow_type' => $this->arrow_type,
            'from_side' => $this->from_side,
            'to_side' => $this->to_side,
        ];
    }
}
