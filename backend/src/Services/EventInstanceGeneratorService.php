<?php

namespace NewSolari\EventPlanning\Services;

use NewSolari\EventPlanning\Models\EventPlan;
use NewSolari\EventPlanning\Models\EventPlanNode;
use NewSolari\EventPlanning\Models\RecurringEventPattern;
use NewSolari\Events\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventInstanceGeneratorService
{
    /**
     * Preview instances that would be generated from a pattern
     *
     * @param RecurringEventPattern $pattern
     * @param int $limit Maximum instances to preview
     * @param Carbon|null $startFrom Start date for preview
     * @return array
     */
    public function previewInstances(
        RecurringEventPattern $pattern,
        int $limit = 10,
        ?Carbon $startFrom = null
    ): array {
        $occurrences = $pattern->getNextOccurrences($limit, $startFrom);
        $instances = [];

        foreach ($occurrences as $occurrence) {
            $instances[] = $this->buildEventInstance($pattern, $occurrence, true);
        }

        return $instances;
    }

    /**
     * Generate event records from a recurring pattern
     *
     * @param RecurringEventPattern $pattern
     * @param int $limit Maximum events to generate
     * @param Carbon|null $startFrom Start date
     * @param bool $addToCanvas Whether to add generated events to the canvas
     * @return array Generated events
     */
    public function generateFromPattern(
        RecurringEventPattern $pattern,
        int $limit = 10,
        ?Carbon $startFrom = null,
        bool $addToCanvas = true
    ): array {
        $eventPlan = $pattern->eventPlan;
        $occurrences = $pattern->getNextOccurrences($limit, $startFrom);
        $generatedEvents = [];

        DB::beginTransaction();
        try {
            foreach ($occurrences as $occurrence) {
                $event = $this->createEventFromPattern($pattern, $occurrence, $eventPlan->partition_id);
                $generatedEvents[] = $event;

                // Add to canvas if requested
                if ($addToCanvas) {
                    $this->addEventToCanvas($event, $eventPlan);
                }
            }

            // Update last generated date
            $pattern->update([
                'last_generated_date' => Carbon::now(),
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $generatedEvents;
    }

    /**
     * Build an event instance object (preview mode)
     */
    protected function buildEventInstance(
        RecurringEventPattern $pattern,
        Carbon $date,
        bool $preview = false
    ): array {
        $template = $pattern->event_template ?? [];

        $title = $template['title'] ?? $pattern->name;
        // Replace date placeholders
        $title = str_replace('{date}', $date->format('M j, Y'), $title);
        $title = str_replace('{day}', $date->format('l'), $title);

        return [
            'preview' => $preview,
            'date' => $date->toDateString(),
            'title' => $title,
            'description' => $template['description'] ?? null,
            'start_time' => $template['start_time'] ?? null,
            'end_time' => $template['end_time'] ?? null,
            'pattern_id' => $pattern->record_id,
            'pattern_name' => $pattern->name,
        ];
    }

    /**
     * Create an actual event record from the pattern
     */
    protected function createEventFromPattern(
        RecurringEventPattern $pattern,
        Carbon $date,
        string $partitionId
    ): Event {
        $template = $pattern->event_template ?? [];

        $title = $template['title'] ?? $pattern->name;
        $title = str_replace('{date}', $date->format('M j, Y'), $title);
        $title = str_replace('{day}', $date->format('l'), $title);

        // Build start datetime
        $startDate = $date->copy();
        if (!empty($template['start_time'])) {
            $time = Carbon::parse($template['start_time']);
            $startDate->setTime($time->hour, $time->minute);
        }

        // Build end datetime
        $endDate = null;
        if (!empty($template['end_time'])) {
            $endDate = $date->copy();
            $time = Carbon::parse($template['end_time']);
            $endDate->setTime($time->hour, $time->minute);
        } elseif (!empty($template['duration_hours'])) {
            $endDate = $startDate->copy()->addHours($template['duration_hours']);
        }

        $event = Event::create([
            'record_id' => Str::uuid()->toString(),
            'partition_id' => $partitionId,
            'title' => $title,
            'description' => $template['description'] ?? null,
            'event_type' => $template['event_type'] ?? 'other',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'all_day' => empty($template['start_time']),
            'status' => 'scheduled',
            'location' => $template['location'] ?? null,
            'tags' => $template['tags'] ?? null,
            'notes' => "Generated from recurring pattern: {$pattern->name}",
            'source_pattern_id' => $pattern->record_id,
            'source_plugin' => 'event-planning-meta-app',
            'source_record_id' => $pattern->eventPlan->record_id,
            'created_by' => $pattern->eventPlan->created_by,
        ]);

        return $event;
    }

    /**
     * Add an event to the canvas as a node
     */
    protected function addEventToCanvas(Event $event, EventPlan $eventPlan): EventPlanNode
    {
        // Calculate position (spread events on the canvas)
        $existingCount = $eventPlan->nodes()->count();
        $row = floor($existingCount / 4);
        $col = $existingCount % 4;

        $x = 100 + ($col * 250);
        $y = 100 + ($row * 150);

        return EventPlanNode::create([
            'record_id' => Str::uuid()->toString(),
            'partition_id' => $eventPlan->partition_id,
            'event_plan_id' => $eventPlan->record_id,
            'entity_type' => 'event',
            'entity_id' => $event->record_id,
            'x' => $x,
            'y' => $y,
            'width' => 200,
            'height' => 100,
            'z_index' => $existingCount,
            'is_pinned' => false,
            'is_collapsed' => false,
        ]);
    }

    /**
     * Delete all events generated from a pattern
     */
    public function deleteGeneratedEvents(RecurringEventPattern $pattern): int
    {
        $deletedCount = 0;

        DB::beginTransaction();
        try {
            // Find events generated from this pattern
            $events = Event::where('source_pattern_id', $pattern->record_id)->get();

            foreach ($events as $event) {
                // Remove from canvas first
                EventPlanNode::where('entity_type', 'event')
                    ->where('entity_id', $event->record_id)
                    ->delete();

                // Delete the event
                $event->delete();
                $deletedCount++;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $deletedCount;
    }
}
