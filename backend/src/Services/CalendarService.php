<?php

namespace NewSolari\EventPlanning\Services;

use NewSolari\EventPlanning\Models\EventPlan;
use NewSolari\EventPlanning\Models\EventPlanNode;
use NewSolari\EventPlanning\Models\RecurringEventPattern;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class CalendarService
{
    /**
     * Get all calendar events for a date range
     *
     * @param EventPlan $eventPlan
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param array $entityTypes Filter by entity types
     * @return array
     */
    public function getEventsForDateRange(
        EventPlan $eventPlan,
        Carbon $startDate,
        Carbon $endDate,
        array $entityTypes = []
    ): array {
        $events = [];

        // 1. Get the event plan itself if it falls within range
        if ($eventPlan->event_date) {
            $eventDate = Carbon::parse($eventPlan->event_date);
            if ($eventDate->between($startDate, $endDate)) {
                $events[] = $this->formatEventPlanEvent($eventPlan);
            }
        }

        // 2. Get linked events from nodes
        $linkedEvents = $this->getLinkedEventsFromNodes($eventPlan, $startDate, $endDate, $entityTypes);
        $events = array_merge($events, $linkedEvents);

        // 3. Get recurring event instances
        $recurringEvents = $this->getRecurringEventInstances($eventPlan, $startDate, $endDate);
        $events = array_merge($events, $recurringEvents);

        // Sort by date
        usort($events, function ($a, $b) {
            return strcmp($a['start_date'], $b['start_date']);
        });

        return $events;
    }

    /**
     * Get events for a specific day
     */
    public function getDayEvents(EventPlan $eventPlan, Carbon $date): array
    {
        return $this->getEventsForDateRange(
            $eventPlan,
            $date->copy()->startOfDay(),
            $date->copy()->endOfDay()
        );
    }

    /**
     * Get events for a week
     */
    public function getWeekEvents(EventPlan $eventPlan, Carbon $date): array
    {
        return $this->getEventsForDateRange(
            $eventPlan,
            $date->copy()->startOfWeek(),
            $date->copy()->endOfWeek()
        );
    }

    /**
     * Get events for a month
     */
    public function getMonthEvents(EventPlan $eventPlan, int $year, int $month): array
    {
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth()->endOfDay();

        return $this->getEventsForDateRange($eventPlan, $startDate, $endDate);
    }

    /**
     * Get calendar month data with days and events
     */
    public function getMonthCalendarData(EventPlan $eventPlan, int $year, int $month): array
    {
        $startDate = Carbon::create($year, $month, 1);
        $endDate = $startDate->copy()->endOfMonth();

        // Get all events for the month
        $events = $this->getMonthEvents($eventPlan, $year, $month);

        // Group events by date
        $eventsByDate = [];
        foreach ($events as $event) {
            $dateKey = Carbon::parse($event['start_date'])->format('Y-m-d');
            if (!isset($eventsByDate[$dateKey])) {
                $eventsByDate[$dateKey] = [];
            }
            $eventsByDate[$dateKey][] = $event;
        }

        // Build calendar grid (include days from prev/next month to fill weeks)
        // Use Sunday (0) as start of week to match frontend expectations
        $calendarStartDate = $startDate->copy()->startOfWeek(Carbon::SUNDAY);
        $calendarEndDate = $endDate->copy()->endOfWeek(Carbon::SATURDAY);

        $days = [];
        $period = CarbonPeriod::create($calendarStartDate, $calendarEndDate);

        foreach ($period as $date) {
            $dateKey = $date->format('Y-m-d');
            $days[] = [
                'date' => $dateKey,
                'day' => $date->day,
                'is_current_month' => $date->month === $month,
                'is_today' => $date->isToday(),
                'is_weekend' => $date->isWeekend(),
                'events' => $eventsByDate[$dateKey] ?? [],
            ];
        }

        // Group into weeks
        $weeks = array_chunk($days, 7);

        return [
            'year' => $year,
            'month' => $month,
            'month_name' => $startDate->format('F'),
            'weeks' => $weeks,
            'total_events' => count($events),
        ];
    }

    /**
     * Get linked events from canvas nodes
     */
    protected function getLinkedEventsFromNodes(
        EventPlan $eventPlan,
        Carbon $startDate,
        Carbon $endDate,
        array $entityTypes = []
    ): array {
        $events = [];

        // Get nodes with event-like entities
        $query = $eventPlan->nodes();

        if (!empty($entityTypes)) {
            $query->whereIn('entity_type', $entityTypes);
        } else {
            // Default: look for events and tasks which have dates
            $query->whereIn('entity_type', ['event', 'task']);
        }

        $nodes = $query->get();

        foreach ($nodes as $node) {
            $entity = $node->resolveEntity();
            if (!$entity) {
                continue;
            }

            // Check if entity has a date field
            $dateField = $this->getEntityDateField($node->entity_type);
            if (!$dateField || empty($entity->$dateField)) {
                continue;
            }

            $entityDate = Carbon::parse($entity->$dateField);
            if (!$entityDate->between($startDate, $endDate)) {
                continue;
            }

            $events[] = $this->formatNodeEvent($node, $entity, $entityDate);
        }

        return $events;
    }

    /**
     * Get the date field name for an entity type
     */
    protected function getEntityDateField(string $entityType): ?string
    {
        return match ($entityType) {
            'event' => 'start_date',
            'task' => 'due_date',
            'invoice' => 'due_date',
            default => null,
        };
    }

    /**
     * Get recurring event instances in date range
     */
    protected function getRecurringEventInstances(
        EventPlan $eventPlan,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        $events = [];

        $patterns = RecurringEventPattern::where('event_plan_id', $eventPlan->record_id)->get();

        foreach ($patterns as $pattern) {
            $occurrences = $this->getPatternOccurrencesInRange($pattern, $startDate, $endDate);
            foreach ($occurrences as $occurrence) {
                $events[] = $this->formatRecurringEvent($pattern, $occurrence);
            }
        }

        return $events;
    }

    /**
     * Get pattern occurrences within a date range
     */
    protected function getPatternOccurrencesInRange(
        RecurringEventPattern $pattern,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        $occurrences = [];

        $patternStart = Carbon::parse($pattern->start_date);

        // Determine the correct starting point for the search
        $searchStart = $this->calculateSearchStart($pattern, $patternStart, $startDate);

        // If the calculated search start is already past the end date, no occurrences
        if ($searchStart->isAfter($endDate)) {
            return $occurrences;
        }

        // Use the model's getNextOccurrences with a larger limit
        $allOccurrences = $pattern->getNextOccurrences(365, $searchStart);

        foreach ($allOccurrences as $occurrence) {
            if ($occurrence->isAfter($endDate)) {
                break;
            }
            if ($occurrence->between($startDate, $endDate)) {
                $occurrences[] = $occurrence;
            }
        }

        return $occurrences;
    }

    /**
     * Calculate the correct search start date that aligns with the pattern
     */
    protected function calculateSearchStart(
        RecurringEventPattern $pattern,
        Carbon $patternStart,
        Carbon $rangeStart
    ): Carbon {
        // If pattern hasn't started yet, start from pattern start
        if ($patternStart->isAfter($rangeStart)) {
            return $patternStart->copy();
        }

        // For weekly patterns, find the first matching day of week on or after rangeStart
        if ($pattern->recurrence_type === 'weekly') {
            $targetDayOfWeek = $pattern->days_of_week[0] ?? $patternStart->dayOfWeek;
            $searchStart = $rangeStart->copy();
            $currentDayOfWeek = $searchStart->dayOfWeek;

            if ($currentDayOfWeek !== $targetDayOfWeek) {
                // Calculate days until next occurrence
                $daysUntil = ($targetDayOfWeek - $currentDayOfWeek + 7) % 7;
                $searchStart->addDays($daysUntil);
            }
            // If already on the correct day, searchStart stays as rangeStart

            return $searchStart;
        }

        // For daily patterns, start from rangeStart
        if ($pattern->recurrence_type === 'daily') {
            return $rangeStart->copy();
        }

        // For monthly/yearly, calculate the first occurrence on or after rangeStart
        if ($pattern->recurrence_type === 'monthly') {
            $searchStart = $rangeStart->copy();
            $dayOfMonth = $pattern->day_of_month ?? $patternStart->day;

            // If we're past the target day this month, go to next month
            if ($searchStart->day > $dayOfMonth) {
                $searchStart->addMonth()->day($dayOfMonth);
            } else {
                $searchStart->day($dayOfMonth);
            }

            return $searchStart;
        }

        // Default: start from range start
        return $rangeStart->copy();
    }

    /**
     * Format the main event plan as a calendar event
     */
    protected function formatEventPlanEvent(EventPlan $eventPlan): array
    {
        return [
            'id' => $eventPlan->record_id,
            'type' => 'event_plan',
            'title' => $eventPlan->title,
            'description' => $eventPlan->description,
            'start_date' => $eventPlan->event_date,
            'end_date' => $eventPlan->event_end_date,
            'all_day' => empty($eventPlan->event_date) || !str_contains($eventPlan->event_date, ':'),
            'color' => $this->getEventTypeColor($eventPlan->event_type),
            'entity_type' => 'event_plan',
            'entity_id' => $eventPlan->record_id,
            'is_recurring' => false,
            'metadata' => [
                'event_type' => $eventPlan->event_type,
                'venue' => $eventPlan->venue,
                'status' => $eventPlan->status,
            ],
        ];
    }

    /**
     * Format a linked node entity as a calendar event
     */
    protected function formatNodeEvent(EventPlanNode $node, $entity, Carbon $date): array
    {
        $endDate = null;
        if (isset($entity->end_date)) {
            $endDate = $entity->end_date;
        }

        return [
            'id' => $node->record_id,
            'type' => 'linked_entity',
            'title' => $node->display_label ?? $entity->name ?? $entity->title ?? 'Untitled',
            'description' => $entity->description ?? null,
            'start_date' => $date->toDateTimeString(),
            'end_date' => $endDate,
            'all_day' => true,
            'color' => $this->getEntityTypeColor($node->entity_type),
            'entity_type' => $node->entity_type,
            'entity_id' => $node->entity_id,
            'node_id' => $node->record_id,
            'is_recurring' => false,
            'metadata' => [
                'status' => $entity->status ?? null,
            ],
        ];
    }

    /**
     * Format a recurring pattern occurrence as a calendar event
     */
    protected function formatRecurringEvent(RecurringEventPattern $pattern, Carbon $date): array
    {
        $template = $pattern->event_template ?? [];

        return [
            'id' => $pattern->record_id . '_' . $date->format('Y-m-d'),
            'type' => 'recurring',
            'title' => $template['title'] ?? $pattern->name,
            'description' => $template['description'] ?? null,
            'start_date' => $date->toDateTimeString(),
            'end_date' => null,
            'all_day' => true,
            'color' => $template['color'] ?? '#8b5cf6',
            'entity_type' => 'recurring_event',
            'entity_id' => $pattern->record_id,
            'pattern_id' => $pattern->record_id,
            'is_recurring' => true,
            'metadata' => [
                'recurrence_type' => $pattern->recurrence_type,
                'pattern_name' => $pattern->name,
            ],
        ];
    }

    /**
     * Get color for event type
     */
    protected function getEventTypeColor(string $eventType): string
    {
        return match ($eventType) {
            'wedding' => '#ec4899',
            'conference' => '#6366f1',
            'party' => '#f59e0b',
            'corporate' => '#64748b',
            'meeting' => '#06b6d4',
            'social' => '#22c55e',
            'fundraiser' => '#a855f7',
            default => '#3b82f6',
        };
    }

    /**
     * Get color for entity type
     */
    protected function getEntityTypeColor(string $entityType): string
    {
        return match ($entityType) {
            'event' => '#f59e0b',
            'task' => '#ef4444',
            'person' => '#3b82f6',
            'place' => '#22c55e',
            'invoice' => '#a855f7',
            'budget' => '#14b8a6',
            default => '#6b7280',
        };
    }
}
