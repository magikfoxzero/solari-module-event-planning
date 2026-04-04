<?php

namespace NewSolari\EventPlanning\Models;

use NewSolari\Core\Entity\BaseEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPlanDrawing extends BaseEntity
{
    protected $table = 'event_plan_drawings';
    protected $primaryKey = 'record_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'record_id',
        'partition_id',
        'event_plan_id',
        'tool',
        'points',
        'color',
        'size',
        'line_style',
        'thickness',
        'arrow_type',
        'text',
        'z_index',
    ];

    protected $casts = [
        'points' => 'array',
        'size' => 'integer',
        'thickness' => 'integer',
        'z_index' => 'integer',
    ];

    protected $validations = [
        'record_id' => 'nullable|string|max:36',
        'partition_id' => 'sometimes|string|max:36|exists:identity_partitions,record_id',
        'event_plan_id' => 'required|string|max:36|exists:event_plans,record_id',
        'tool' => 'required|string|in:pencil,line,rectangle,circle,arrow,text,highlighter,eraser',
        'points' => 'required|array',
        'color' => 'nullable|string|max:20',
        'size' => 'nullable|integer|min:1|max:100',
        'line_style' => 'nullable|string|in:solid,dashed,dotted',
        'thickness' => 'nullable|integer|min:1|max:20',
        'arrow_type' => 'nullable|string|in:none,one-way,two-way',
        'text' => 'nullable|string|max:500',
        'z_index' => 'nullable|integer|min:0',
    ];

    /**
     * Drawing tools
     */
    public const TOOLS = [
        'pencil' => 'Pencil',
        'line' => 'Line',
        'rectangle' => 'Rectangle',
        'circle' => 'Circle',
        'arrow' => 'Arrow',
        'text' => 'Text',
        'highlighter' => 'Highlighter',
        'eraser' => 'Eraser',
    ];

    /**
     * Get the parent event plan
     */
    public function eventPlan(): BelongsTo
    {
        return $this->belongsTo(EventPlan::class, 'event_plan_id', 'record_id');
    }

    /**
     * Get bounding box
     */
    public function getBoundingBox(): array
    {
        $points = $this->points ?? [];
        if (empty($points)) {
            return ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0];
        }

        $xs = array_column($points, 'x');
        $ys = array_column($points, 'y');

        $minX = min($xs);
        $maxX = max($xs);
        $minY = min($ys);
        $maxY = max($ys);

        return [
            'x' => $minX,
            'y' => $minY,
            'width' => $maxX - $minX,
            'height' => $maxY - $minY,
        ];
    }
}
