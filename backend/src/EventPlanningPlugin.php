<?php

namespace NewSolari\EventPlanning;

use NewSolari\EventPlanning\Models\EventPlan;
use NewSolari\Core\Plugin\MetaAppBase;

/**
 * Event Planning Meta-App Plugin.
 *
 * Provides comprehensive event planning functionality including calendar views,
 * seat planning, canvas/mood boards, and budget tracking integration.
 */
class EventPlanningPlugin extends MetaAppBase
{
    /**
     * EventPlanningPlugin constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->pluginId = 'event-planning-meta-app';
        $this->pluginName = 'Event Planning';
        $this->description = 'Comprehensive event planning with calendar, seating, and canvas views';
        $this->version = '1.0.0';

        $this->permissions = [
            'event-planning.create',
            'event-planning.read',
            'event-planning.update',
            'event-planning.delete',
            'event-planning.manage',
            'event-planning.export',
        ];

        $this->miniAppDependencies = [
            'people-mini-app',
            'places-mini-app',
            'events-mini-app',
            'entities-mini-app',
            'notes-mini-app',
            'tasks-mini-app',
            'files-mini-app',
            'inventory-objects-mini-app',
            'tags-mini-app',
            'hypotheses-mini-app',
            'motives-mini-app',
            'budgets-mini-app',
            'invoices-mini-app',
        ];

        $this->routes = [
            '/api/event-plans',
            '/api/event-plans/{id}',
            '/api/event-plans/search',
            '/api/event-plans/export',
            '/api/event-plans/stats',
            '/api/event-plans/{id}/graph',
            '/api/event-plans/{id}/nodes',
            '/api/event-plans/{id}/nodes/bulk',
            '/api/event-plans/{id}/nodes/positions',
            '/api/event-plans/{id}/nodes/{nodeId}',
            '/api/event-plans/{id}/connections',
            '/api/event-plans/{id}/connections/{connId}',
            '/api/event-plans/{id}/drawings',
            '/api/event-plans/{id}/drawings/{drawingId}',
            '/api/event-plans/{id}/calendar',
            '/api/event-plans/{id}/recurring-patterns',
        ];

        $this->database = [
            'migrations' => [
                'create_event_planning_tables',
            ],
            'models' => [
                'EventPlan',
                'EventPlanNode',
                'EventPlanConnection',
                'EventPlanDrawing',
                'RecurringEventPattern',
            ],
        ];
    }

    /**
     * Get the container model class.
     */
    public function getContainerModel(): string
    {
        return EventPlan::class;
    }

    /**
     * Get container data validation rules.
     */
    public function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'event_type' => 'nullable|string|max:50',
            'status' => 'sometimes|in:draft,planning,active,on_hold,completed,cancelled',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'timezone' => 'nullable|string|max:50',
            'venue_place_id' => 'nullable|string|max:36',
            'organizer_entity_id' => 'nullable|string|max:36',
            'expected_guests' => 'nullable|integer|min:0',
            'budget_id' => 'nullable|string|exists:budgets,record_id',
            'default_view' => 'sometimes|in:calendar,seats,canvas',
            'is_public' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ];
    }

    /**
     * Get linkable entity types for canvas.
     */
    public function getLinkableEntityTypes(): array
    {
        return [
            'person',
            'place',
            'event',
            'note',
            'task',
            'file',
            'entity',
            'inventory_object',
            'tag',
            'hypothesis',
            'motive',
            'budget',
            'invoice',
        ];
    }

    /**
     * Get timeline date fields for entities.
     */
    public function getTimelineDateFields(): array
    {
        return [
            'event' => ['start_date', 'end_date'],
            'task' => ['due_date'],
            'invoice' => ['issue_date', 'due_date'],
        ];
    }

    /**
     * Get visual configuration for entity types.
     */
    public function getEntityVisualConfig(): array
    {
        return [
            'person' => ['icon' => 'user', 'color' => '#3b82f6'],
            'place' => ['icon' => 'map-pin', 'color' => '#22c55e'],
            'event' => ['icon' => 'calendar', 'color' => '#f59e0b'],
            'note' => ['icon' => 'file-text', 'color' => '#8b5cf6'],
            'task' => ['icon' => 'check-square', 'color' => '#ef4444'],
            'file' => ['icon' => 'file', 'color' => '#6b7280'],
            'entity' => ['icon' => 'building', 'color' => '#06b6d4'],
            'inventory_object' => ['icon' => 'package', 'color' => '#ec4899'],
            'tag' => ['icon' => 'tag', 'color' => '#84cc16'],
            'hypothesis' => ['icon' => 'lightbulb', 'color' => '#fbbf24'],
            'motive' => ['icon' => 'target', 'color' => '#f97316'],
            'budget' => ['icon' => 'wallet', 'color' => '#14b8a6'],
            'invoice' => ['icon' => 'receipt', 'color' => '#a855f7'],
        ];
    }

    /**
     * Get integration routes for this meta-app.
     */
    public function getIntegrationRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Initialize integration logic.
     */
    protected function initializeIntegrationLogic(): void
    {
        // Event planning integration logic initialization
        // Can be extended for specific integration behaviors
    }

    /**
     * Get plugin info.
     */
    public function getPluginInfo(): array
    {
        return [
            'id' => $this->pluginId,
            'name' => $this->pluginName,
            'type' => 'meta-app',
            'description' => $this->description,
            'version' => $this->version,
            'permissions' => $this->permissions,
            'dependencies' => $this->miniAppDependencies,
            'features' => [
                'supports_canvas' => true,
                'supports_calendar' => true,
                'supports_seating' => true,
                'supports_timeline' => true,
                'supports_export' => true,
                'supports_search' => true,
                'supports_statistics' => true,
                'supports_recurring_events' => true,
            ],
        ];
    }
}
