<?php

namespace NewSolari\EventPlanning\Models;

use NewSolari\Core\Entity\BaseEntity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringEventPattern extends BaseEntity
{
    protected $table = 'recurring_event_patterns';
    protected $primaryKey = 'record_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'record_id',
        'partition_id',
        'event_plan_id',
        'name',
        'recurrence_type',
        'interval_value',
        'days_of_week',
        'day_of_month',
        'week_of_month',
        'month_of_year',
        'start_date',
        'end_date',
        'max_occurrences',
        'event_template',
        'last_generated_date',
    ];

    protected $casts = [
        'interval_value' => 'integer',
        'days_of_week' => 'array',
        'day_of_month' => 'integer',
        'week_of_month' => 'integer',
        'month_of_year' => 'integer',
        'max_occurrences' => 'integer',
        'event_template' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'last_generated_date' => 'date',
    ];

    protected $validations = [
        'record_id' => 'nullable|string|max:36',
        'partition_id' => 'sometimes|string|max:36|exists:identity_partitions,record_id',
        'event_plan_id' => 'required|string|max:36|exists:event_plans,record_id',
        'name' => 'required|string|max:255',
        'recurrence_type' => 'required|string|in:none,daily,weekly,monthly,yearly,custom',
        'interval_value' => 'nullable|integer|min:1',
        'days_of_week' => 'nullable|array',
        'day_of_month' => 'nullable|integer|min:1|max:31',
        'week_of_month' => 'nullable|integer|min:1|max:5',
        'month_of_year' => 'nullable|integer|min:1|max:12',
        'start_date' => 'required|date',
        'end_date' => 'nullable|date|after_or_equal:start_date',
        'max_occurrences' => 'nullable|integer|min:1',
        'event_template' => 'nullable|array',
        'last_generated_date' => 'nullable|date',
    ];

    /**
     * Recurrence types
     */
    public const RECURRENCE_TYPES = [
        'none' => 'One-time',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'custom' => 'Custom',
    ];

    /**
     * Get the parent event plan
     */
    public function eventPlan(): BelongsTo
    {
        return $this->belongsTo(EventPlan::class, 'event_plan_id', 'record_id');
    }

    /**
     * Get next occurrences up to limit
     */
    public function getNextOccurrences(int $limit = 10, ?Carbon $startFrom = null): array
    {
        $occurrences = [];
        $patternStartDate = Carbon::parse($this->start_date);
        $current = $startFrom ?? $patternStartDate->copy();
        $endDate = $this->end_date ? Carbon::parse($this->end_date) : null;
        $count = 0;
        $iterations = 0;
        $maxIterations = 1000;

        // Special handling for one-time events
        if ($this->recurrence_type === 'none') {
            // Only check if start_date is on or after the search start
            if ($patternStartDate->greaterThanOrEqualTo($current->startOfDay())) {
                if (!$endDate || $patternStartDate->lessThanOrEqualTo($endDate)) {
                    $occurrences[] = $patternStartDate->copy();
                }
            }
            return $occurrences;
        }

        while (count($occurrences) < $limit && $iterations < $maxIterations) {
            $iterations++;

            // Check end date
            if ($endDate && $current->isAfter($endDate)) {
                break;
            }

            // Check max occurrences
            if ($this->max_occurrences && $count >= $this->max_occurrences) {
                break;
            }

            // Check if this date matches the pattern
            if ($this->matchesPattern($current)) {
                $occurrences[] = $current->copy();
                $count++;
            }

            // Move to next potential date
            $current = $this->advanceDate($current);
        }

        return $occurrences;
    }

    /**
     * Check if a date matches this pattern
     */
    protected function matchesPattern(Carbon $date): bool
    {
        switch ($this->recurrence_type) {
            case 'none':
                // One-time event: only matches the start date
                return $date->isSameDay(Carbon::parse($this->start_date));

            case 'daily':
                return true;

            case 'weekly':
                $daysOfWeek = $this->days_of_week ?? [];
                // If no specific days set, use the day of week from start_date
                if (empty($daysOfWeek)) {
                    $startDayOfWeek = Carbon::parse($this->start_date)->dayOfWeek;
                    return $date->dayOfWeek === $startDayOfWeek;
                }
                return in_array($date->dayOfWeek, $daysOfWeek);

            case 'monthly':
                if ($this->day_of_month) {
                    return $date->day === $this->day_of_month;
                }
                if ($this->week_of_month) {
                    return $date->weekOfMonth === $this->week_of_month &&
                           (empty($this->days_of_week) || in_array($date->dayOfWeek, $this->days_of_week));
                }
                return true;

            case 'yearly':
                if ($this->month_of_year && $date->month !== $this->month_of_year) {
                    return false;
                }
                if ($this->day_of_month && $date->day !== $this->day_of_month) {
                    return false;
                }
                return true;

            default:
                return true;
        }
    }

    /**
     * Advance date to next potential occurrence
     */
    protected function advanceDate(Carbon $date): Carbon
    {
        // Ensure interval_value is at least 1 to prevent infinite loops
        $interval = max(1, $this->interval_value ?? 1);

        switch ($this->recurrence_type) {
            case 'daily':
                return $date->addDays($interval);

            case 'weekly':
                return $date->addWeeks($interval);

            case 'monthly':
                return $date->addMonths($interval);

            case 'yearly':
                return $date->addYears($interval);

            default:
                return $date->addDay();
        }
    }
}
