<?php

namespace NewSolari\EventPlanning\Models;

use NewSolari\Core\Entity\BaseEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatAssignment extends BaseEntity
{
    protected $table = 'seat_assignments';

    public const RSVP_STATUSES = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'declined' => 'Declined',
        'tentative' => 'Tentative',
    ];

    protected $fillable = [
        'partition_id',
        'seat_table_id',
        'person_id',
        'guest_name',
        'guest_email',
        'seat_number',
        'seat_x',
        'seat_y',
        'custom_position',
        'dietary_requirements',
        'accessibility_needs',
        'notes',
        'rsvp_status',
        'checked_in',
        'checked_in_at',
    ];

    protected $casts = [
        'seat_number' => 'integer',
        'seat_x' => 'float',
        'seat_y' => 'float',
        'custom_position' => 'boolean',
        'checked_in' => 'boolean',
        'checked_in_at' => 'datetime',
    ];

    protected $validations = [
        'record_id' => 'nullable|string|max:36',
        'partition_id' => 'sometimes|string|max:36|exists:identity_partitions,record_id',
        'seat_table_id' => 'required|string|max:36|exists:seat_tables,record_id',
        'person_id' => 'nullable|string|max:36|exists:people,record_id',
        'guest_name' => 'nullable|string|max:255',
        'guest_email' => 'nullable|email|max:255',
        'seat_number' => 'required|integer|min:1',
        'seat_x' => 'nullable|numeric',
        'seat_y' => 'nullable|numeric',
        'custom_position' => 'boolean',
        'dietary_requirements' => 'nullable|string|max:500',
        'accessibility_needs' => 'nullable|string|max:500',
        'notes' => 'nullable|string',
        'rsvp_status' => 'nullable|string|in:pending,confirmed,declined,tentative',
        'checked_in' => 'boolean',
        'checked_in_at' => 'nullable|date',
    ];

    protected $appends = [
        'display_name',
    ];

    /**
     * Get the table that owns this assignment.
     */
    public function seatTable(): BelongsTo
    {
        return $this->belongsTo(SeatTable::class, 'seat_table_id', 'record_id');
    }

    /**
     * Get the linked person.
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(\NewSolari\People\Models\Person::class, 'person_id', 'record_id');
    }

    /**
     * Get display name (either from person or guest_name).
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->person_id && $this->relationLoaded('person') && $this->person) {
            return $this->person->display_name ?? $this->person->first_name . ' ' . $this->person->last_name;
        }

        if ($this->person_id) {
            $person = $this->person;
            if ($person) {
                return $person->display_name ?? $person->first_name . ' ' . $person->last_name;
            }
        }

        return $this->guest_name ?? 'Unassigned';
    }

    /**
     * Get email (either from person or guest_email).
     */
    public function getEmailAttribute(): ?string
    {
        if ($this->person_id) {
            $person = $this->person;
            if ($person) {
                return $person->email;
            }
        }

        return $this->guest_email;
    }

    /**
     * Mark guest as checked in.
     */
    public function checkIn(): void
    {
        $this->checked_in = true;
        $this->checked_in_at = now();
        $this->save();
    }

    /**
     * Undo check-in.
     */
    public function undoCheckIn(): void
    {
        $this->checked_in = false;
        $this->checked_in_at = null;
        $this->save();
    }

    /**
     * Update RSVP status.
     */
    public function updateRsvpStatus(string $status): void
    {
        if (array_key_exists($status, self::RSVP_STATUSES)) {
            $this->rsvp_status = $status;
            $this->save();
        }
    }
}
