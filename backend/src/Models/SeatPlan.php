<?php

namespace NewSolari\EventPlanning\Models;

use NewSolari\Core\Entity\BaseEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SeatPlan extends BaseEntity
{
    protected $table = 'seat_plans';

    public const LAYOUT_TYPES = [
        'banquet' => 'Banquet',
        'theater' => 'Theater',
        'classroom' => 'Classroom',
        'conference' => 'Conference',
        'custom' => 'Custom',
    ];

    protected $fillable = [
        'partition_id',
        'event_plan_id',
        'name',
        'description',
        'layout_type',
        'canvas_state',
        'floor_plan_image_id',
    ];

    protected $casts = [
        'canvas_state' => 'array',
    ];

    protected $validations = [
        'record_id' => 'nullable|string|max:36',
        'partition_id' => 'sometimes|string|max:36|exists:identity_partitions,record_id',
        'event_plan_id' => 'required|string|max:36|exists:event_plans,record_id',
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'layout_type' => 'nullable|string|in:banquet,theater,classroom,conference,custom',
        'canvas_state' => 'nullable|array',
        'floor_plan_image_id' => 'nullable|string|max:36|exists:files,record_id',
    ];

    protected $appends = [
        'total_capacity',
        'assigned_count',
        'table_count',
    ];

    /**
     * Get the event plan that owns this seat plan.
     */
    public function eventPlan(): BelongsTo
    {
        return $this->belongsTo(EventPlan::class, 'event_plan_id', 'record_id');
    }

    /**
     * Get the tables in this seat plan.
     */
    public function tables(): HasMany
    {
        return $this->hasMany(SeatTable::class, 'seat_plan_id', 'record_id');
    }

    /**
     * Get all assignments through tables.
     */
    public function assignments(): HasManyThrough
    {
        return $this->hasManyThrough(
            SeatAssignment::class,
            SeatTable::class,
            'seat_plan_id', // Foreign key on seat_tables
            'seat_table_id', // Foreign key on seat_assignments
            'record_id', // Local key on seat_plans
            'record_id' // Local key on seat_tables
        );
    }

    /**
     * Get the floor plan image file.
     */
    public function floorPlanImage(): BelongsTo
    {
        return $this->belongsTo(\NewSolari\Files\Models\File::class, 'floor_plan_image_id', 'record_id');
    }

    /**
     * Get total capacity across all tables.
     */
    public function getTotalCapacityAttribute(): int
    {
        return $this->tables->sum('capacity');
    }

    /**
     * Get count of assigned seats.
     */
    public function getAssignedCountAttribute(): int
    {
        return $this->assignments()->count();
    }

    /**
     * Get table count.
     */
    public function getTableCountAttribute(): int
    {
        return $this->tables->count();
    }

    /**
     * Get available seat count.
     */
    public function getAvailableSeatsAttribute(): int
    {
        return $this->total_capacity - $this->assigned_count;
    }

    /**
     * Get checked in count.
     */
    public function getCheckedInCountAttribute(): int
    {
        return $this->assignments()->where('checked_in', true)->count();
    }

    /**
     * Get RSVP confirmed count.
     */
    public function getConfirmedCountAttribute(): int
    {
        return $this->assignments()->where('rsvp_status', 'confirmed')->count();
    }

    /**
     * Update canvas state.
     */
    public function updateCanvasState(array $state): void
    {
        $this->canvas_state = array_merge($this->canvas_state ?? [], $state);
        $this->save();
    }
}
