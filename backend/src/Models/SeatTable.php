<?php

namespace NewSolari\EventPlanning\Models;

use NewSolari\Core\Entity\BaseEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeatTable extends BaseEntity
{
    protected $table = 'seat_tables';

    public const TABLE_TYPES = [
        'round' => 'Round',
        'rectangular' => 'Rectangular',
        'square' => 'Square',
        'custom' => 'Custom',
    ];

    public const DEFAULT_CAPACITIES = [
        'round' => 8,
        'rectangular' => 10,
        'square' => 4,
        'custom' => 8,
    ];

    public const DEFAULT_DIMENSIONS = [
        'round' => ['width' => 120, 'height' => 120],
        'rectangular' => ['width' => 200, 'height' => 80],
        'square' => ['width' => 100, 'height' => 100],
        'custom' => ['width' => 150, 'height' => 150],
    ];

    protected $fillable = [
        'partition_id',
        'seat_plan_id',
        'name',
        'table_number',
        'table_type',
        'capacity',
        'x',
        'y',
        'rotation',
        'width',
        'height',
        'color',
        'custom_seat_positions',
        'notes',
    ];

    protected $casts = [
        'table_number' => 'integer',
        'capacity' => 'integer',
        'x' => 'float',
        'y' => 'float',
        'rotation' => 'float',
        'width' => 'float',
        'height' => 'float',
        'custom_seat_positions' => 'array',
    ];

    protected $validations = [
        'record_id' => 'nullable|string|max:36',
        'partition_id' => 'sometimes|string|max:36|exists:identity_partitions,record_id',
        'seat_plan_id' => 'required|string|max:36|exists:seat_plans,record_id',
        'name' => 'required|string|max:255',
        'table_number' => 'nullable|integer|min:1',
        'table_type' => 'nullable|string|in:round,rectangular,square,custom',
        'capacity' => 'required|integer|min:1|max:100',
        'x' => 'required|numeric',
        'y' => 'required|numeric',
        'rotation' => 'nullable|numeric|min:0|max:360',
        'width' => 'nullable|numeric|min:0',
        'height' => 'nullable|numeric|min:0',
        'color' => 'nullable|string|max:20',
        'custom_seat_positions' => 'nullable|array',
        'notes' => 'nullable|string',
    ];

    protected $appends = [
        'assigned_count',
        'available_seats',
    ];

    /**
     * Get the seat plan that owns this table.
     */
    public function seatPlan(): BelongsTo
    {
        return $this->belongsTo(SeatPlan::class, 'seat_plan_id', 'record_id');
    }

    /**
     * Get the seat assignments for this table.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(SeatAssignment::class, 'seat_table_id', 'record_id')
            ->orderBy('seat_number');
    }

    /**
     * Get assigned count.
     */
    public function getAssignedCountAttribute(): int
    {
        return $this->assignments->count();
    }

    /**
     * Get available seats count.
     */
    public function getAvailableSeatsAttribute(): int
    {
        return max(0, $this->capacity - $this->assigned_count);
    }

    /**
     * Check if table is full.
     */
    public function getIsFullAttribute(): bool
    {
        return $this->assigned_count >= $this->capacity;
    }

    /**
     * Get next available seat number.
     */
    public function getNextAvailableSeatNumber(): ?int
    {
        $assignedSeats = $this->assignments->pluck('seat_number')->toArray();

        for ($i = 1; $i <= $this->capacity; $i++) {
            if (!in_array($i, $assignedSeats)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Get assigned guests.
     */
    public function getAssignedGuestsAttribute(): array
    {
        return $this->assignments->map(function ($assignment) {
            return [
                'seat_number' => $assignment->seat_number,
                'name' => $assignment->display_name,
                'rsvp_status' => $assignment->rsvp_status,
                'checked_in' => $assignment->checked_in,
            ];
        })->toArray();
    }

    /**
     * Get display name for table.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->table_number) {
            return "Table {$this->table_number}: {$this->name}";
        }
        return $this->name;
    }

    /**
     * Update custom position for a specific seat number.
     */
    public function setSeatPosition(int $seatNumber, float $x, float $y): void
    {
        $positions = $this->custom_seat_positions ?? [];
        $positions[(string) $seatNumber] = ['x' => $x, 'y' => $y];
        $this->custom_seat_positions = $positions;
        $this->save();
    }

    /**
     * Reset position for a specific seat number.
     */
    public function resetSeatPosition(int $seatNumber): void
    {
        $positions = $this->custom_seat_positions ?? [];
        unset($positions[(string) $seatNumber]);
        $this->custom_seat_positions = empty($positions) ? null : $positions;
        $this->save();
    }

    /**
     * Reset all custom seat positions.
     */
    public function resetAllSeatPositions(): void
    {
        $this->custom_seat_positions = null;
        $this->save();
    }

    /**
     * Get custom position for a seat number, if set.
     */
    public function getCustomSeatPosition(int $seatNumber): ?array
    {
        $positions = $this->custom_seat_positions ?? [];
        return $positions[(string) $seatNumber] ?? null;
    }

    /**
     * Check if any seats have custom positions.
     */
    public function hasCustomPositions(): bool
    {
        return !empty($this->custom_seat_positions);
    }
}
