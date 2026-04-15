<?php

namespace NewSolari\EventPlanning\Controllers;

use NewSolari\Core\Http\BaseController;
use NewSolari\Core\Http\Traits\RelationshipControllerTrait;
use NewSolari\Core\Contracts\IdentityUserContract;
use NewSolari\EventPlanning\Models\EventPlan;
use NewSolari\EventPlanning\Models\EventPlanNode;
use NewSolari\EventPlanning\Models\EventPlanConnection;
use NewSolari\EventPlanning\Models\EventPlanDrawing;
use NewSolari\EventPlanning\Models\RecurringEventPattern;
use NewSolari\EventPlanning\Models\SeatPlan;
use NewSolari\EventPlanning\Models\SeatTable;
use NewSolari\EventPlanning\Models\SeatAssignment;
use NewSolari\EventPlanning\Requests\StoreEventPlanRequest;
use NewSolari\EventPlanning\Requests\StoreNodeRequest;
use NewSolari\EventPlanning\Requests\StoreConnectionRequest;
use NewSolari\EventPlanning\Requests\StoreRecurringPatternRequest;
use NewSolari\EventPlanning\Requests\StoreSeatPlanRequest;
use NewSolari\EventPlanning\Requests\UpdateSeatPlanRequest;
use NewSolari\EventPlanning\Requests\StoreSeatTableRequest;
use NewSolari\EventPlanning\Requests\UpdateSeatTableRequest;
use NewSolari\EventPlanning\Requests\StoreSeatAssignmentRequest;
use NewSolari\EventPlanning\Requests\UpdateSeatAssignmentRequest;
use NewSolari\EventPlanning\Services\CalendarService;
use NewSolari\EventPlanning\Services\EventInstanceGeneratorService;
use NewSolari\EventPlanning\Services\SeatPlanLayoutService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventPlanningController extends BaseController
{
    use RelationshipControllerTrait;

    /**
     * List event plans with pagination and filtering
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $partitionId = $request->header('X-Partition-ID');
            $user = $this->getAuthenticatedUser($request);

            if (!$partitionId && !$user->is_system_user) {
                return $this->errorResponse('Partition ID is required', 400);
            }

            $query = EventPlan::with(['budget', 'creator']);

            // System admins see all; others are filtered by partition and access control
            if (!$user->is_system_user) {
                $query->where('partition_id', $partitionId);
                $this->applyAccessControl($query, $user, $partitionId);
            }

            // Status filter
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Event type filter
            if ($request->has('event_type')) {
                $query->where('event_type', $request->event_type);
            }

            // Date range filter
            if ($request->has('from_date')) {
                $query->whereDate('start_date', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('start_date', '<=', $request->to_date);
            }

            // Search - escape LIKE wildcards to prevent pattern injection
            if ($request->has('search') && $request->search) {
                $search = $this->escapeLikePattern($request->search);
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('notes', 'like', "%{$search}%");
                });
            }

            // Sorting - whitelist allowed columns to prevent SQL injection
            $allowedSortColumns = [
                'start_date', 'end_date', 'title', 'status', 'event_type',
                'expected_guests', 'created_at', 'updated_at'
            ];
            $sortBy = $request->get('sort_by', 'start_date');
            $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'start_date';
            $sortDir = strtolower($request->get('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
            $query->orderBy($sortBy, $sortDir);

            // Pagination
            $perPage = min($request->get('per_page', 20), 100);
            $eventPlans = $query->paginate($perPage);

            return $this->successResponse([
                'event_plans' => $eventPlans->items(),
                'pagination' => [
                    'total' => $eventPlans->total(),
                    'per_page' => $eventPlans->perPage(),
                    'current_page' => $eventPlans->currentPage(),
                    'last_page' => $eventPlans->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list event plans', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return $this->errorResponse('Failed to list event plans', 500);
        }
    }

    /**
     * Create a new event plan
     */
    public function store(StoreEventPlanRequest $request): JsonResponse
    {
        $user = null;
        try {
            $partitionId = $request->header('X-Partition-ID');
            $user = $this->getAuthenticatedUser($request);

            if (!$partitionId && !$user->is_system_user) {
                return $this->errorResponse('Partition ID is required', 400);
            }
            $data = $request->validated();

            $eventPlan = EventPlan::create([
                'record_id' => Str::uuid()->toString(),
                'partition_id' => $partitionId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'event_type' => $data['event_type'] ?? null,
                'status' => $data['status'] ?? 'planning',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'timezone' => $data['timezone'] ?? 'UTC',
                'venue_place_id' => $data['venue_place_id'] ?? null,
                'organizer_entity_id' => $data['organizer_entity_id'] ?? null,
                'expected_guests' => $data['expected_guests'] ?? 0,
                'budget_id' => $data['budget_id'] ?? null,
                'default_view' => $data['default_view'] ?? 'calendar',
                'is_public' => $data['is_public'] ?? false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->record_id,
            ]);

            $eventPlan->load(['budget', 'creator']);

            return $this->successResponse(['event_plan' => $eventPlan], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create event plan', [
                'error' => $e->getMessage(),
                'user_id' => $user->record_id ?? null,
            ]);
            return $this->errorResponse('Failed to create event plan', 500);
        }
    }

    /**
     * Get a single event plan with all details
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $partitionId = $request->header('X-Partition-ID');
            $user = $this->getAuthenticatedUser($request);

            if (!$partitionId && !$user->is_system_user) {
                return $this->errorResponse('Partition ID is required', 400);
            }

            // Build query - system admins can access any event plan
            $query = EventPlan::with(['budget', 'creator', 'nodes', 'connections', 'drawings'])
                ->where('record_id', $id);

            // System admins see all; others are filtered by partition
            if (!$user->is_system_user) {
                $query->where('partition_id', $partitionId);
            }

            $eventPlan = $query->first();

            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            // Check user-level access control - system admins bypass this
            if (!$user->is_system_user && !$this->canAccessEventPlan($eventPlan, $user, $partitionId ?? $eventPlan->partition_id)) {
                return $this->errorResponse('Event plan not found', 404);
            }

            return $this->successResponse(['event_plan' => $eventPlan]);
        } catch (\Exception $e) {
            Log::error('Failed to get event plan', [
                'event_plan_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Event plan not found', 404);
        }
    }

    /**
     * Update an event plan
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = null;
        try {
            $partitionId = $request->header('X-Partition-ID');
            $user = $this->getAuthenticatedUser($request);

            if (!$partitionId && !$user->is_system_user) {
                return $this->errorResponse('Partition ID is required', 400);
            }

            // Build query - system admins can access any event plan
            $query = EventPlan::where('record_id', $id);
            // System admins see all; others are filtered by partition
            if (!$user->is_system_user) {
                $query->where('partition_id', $partitionId);
            }

            $eventPlan = $query->first();

            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            // Check write access (must be creator or admin) - system admins bypass this
            if (!$user->is_system_user && !$this->canWriteEventPlan($eventPlan, $user, $partitionId ?? $eventPlan->partition_id)) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $validated = $request->validate([
                'title' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'event_type' => 'nullable|string|max:50',
                'status' => 'sometimes|in:planning,confirmed,in_progress,completed,cancelled',
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
                'canvas_state' => 'nullable|array',
            ]);

            $validated['updated_by'] = $user->record_id;
            $eventPlan->update($validated);
            $eventPlan->load(['budget', 'creator']);

            return $this->successResponse(['event_plan' => $eventPlan]);
        } catch (\Exception $e) {
            Log::error('Failed to update event plan', [
                'event_plan_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => $user->record_id ?? null,
            ]);
            return $this->errorResponse('Failed to update event plan', 500);
        }
    }

    /**
     * Delete an event plan (soft delete)
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $partitionId = $request->header('X-Partition-ID');
            $user = $this->getAuthenticatedUser($request);

            if (!$partitionId && !$user->is_system_user) {
                return $this->errorResponse('Partition ID is required', 400);
            }

            // Build query - system admins can access any event plan
            $query = EventPlan::where('record_id', $id);
            // System admins see all; others are filtered by partition
            if (!$user->is_system_user) {
                $query->where('partition_id', $partitionId);
            }

            $eventPlan = $query->first();

            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            // Check write access (must be creator or admin) - system admins bypass this
            if (!$user->is_system_user && !$this->canWriteEventPlan($eventPlan, $user, $partitionId ?? $eventPlan->partition_id)) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $eventPlan->delete();

            return $this->successResponse(['message' => 'Event plan deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to delete event plan', [
                'event_plan_id' => $id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return $this->errorResponse('Failed to delete event plan', 500);
        }
    }

    /**
     * Search event plans
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $partitionId = $request->header('X-Partition-ID');
            $user = $this->getAuthenticatedUser($request);

            if (!$partitionId && !$user->is_system_user) {
                return $this->errorResponse('Partition ID is required', 400);
            }

            $search = $request->get('q', '');
            if (strlen($search) < 2) {
                return $this->successResponse(['event_plans' => []]);
            }

            $escapedSearch = $this->escapeLikePattern($search);
            $query = EventPlan::where(function ($q) use ($escapedSearch) {
                    $q->where('title', 'like', "%{$escapedSearch}%")
                      ->orWhere('description', 'like', "%{$escapedSearch}%");
                });

            // System admins see all; others are filtered by partition and access control
            if (!$user->is_system_user) {
                $query->where('partition_id', $partitionId);
                $this->applyAccessControl($query, $user, $partitionId);
            }

            $eventPlans = $query->limit(20)->get();

            return $this->successResponse(['event_plans' => $eventPlans]);
        } catch (\Exception $e) {
            Log::error('Failed to search event plans', [
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to search event plans', 500);
        }
    }

    /**
     * Get event plan statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $partitionId = $request->header('X-Partition-ID');
            $user = $this->getAuthenticatedUser($request);

            if (!$partitionId && !$user->is_system_user) {
                return $this->errorResponse('Partition ID is required', 400);
            }

            // Base query with access control
            $baseQuery = function () use ($partitionId, $user) {
                $query = EventPlan::query();

                // System admins see all; others are filtered by partition and access control
                if (!$user->is_system_user) {
                    $query->where('partition_id', $partitionId);
                    $this->applyAccessControl($query, $user, $partitionId);
                }

                return $query;
            };

            // Status counts
            $statusQuery = $baseQuery();
            $statusCounts = $statusQuery
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');

            // Upcoming events
            $upcomingQuery = $baseQuery();
            $upcomingCount = $upcomingQuery
                ->whereDate('start_date', '>=', now())
                ->count();

            // Event type counts
            $typeQuery = $baseQuery();
            $typeCounts = $typeQuery
                ->whereNotNull('event_type')
                ->selectRaw('event_type, COUNT(*) as count')
                ->groupBy('event_type')
                ->pluck('count', 'event_type');

            // Total nodes across accessible event plans only
            $accessibleEventPlanIds = $baseQuery()->pluck('record_id');
            $totalNodes = EventPlanNode::whereIn('event_plan_id', $accessibleEventPlanIds)->count();

            // Response matches frontend EventPlanStats interface
            $totalQuery = $baseQuery();
            $stats = [
                'total' => $totalQuery->count(),
                'by_status' => $statusCounts,
                'by_type' => $typeCounts,
                'total_nodes' => $totalNodes,
                'upcoming' => $upcomingCount,
            ];

            return $this->successResponse($stats);
        } catch (\Exception $e) {
            Log::error('Failed to get event plan statistics', [
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to get statistics', 500);
        }
    }

    // =====================================
    // Calendar Methods
    // =====================================

    /**
     * Get calendar events for a date range
     */
    public function calendar(Request $request, string $id): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'entity_types' => 'nullable|array',
                'entity_types.*' => 'string',
            ]);

            $calendarService = new CalendarService();
            $events = $calendarService->getEventsForDateRange(
                $eventPlan,
                Carbon::parse($validated['start_date']),
                Carbon::parse($validated['end_date']),
                $validated['entity_types'] ?? []
            );

            return $this->successResponse([
                'events' => $events,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get calendar events', [
                'event_plan_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to get calendar events', 500);
        }
    }

    /**
     * Get calendar day view
     */
    public function calendarDay(Request $request, string $id, string $date): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $calendarService = new CalendarService();
            $events = $calendarService->getDayEvents($eventPlan, Carbon::parse($date));

            return $this->successResponse([
                'date' => $date,
                'events' => $events,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get calendar day', [
                'event_plan_id' => $id,
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to get calendar day', 500);
        }
    }

    /**
     * Get calendar week view
     */
    public function calendarWeek(Request $request, string $id, string $date): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $calendarService = new CalendarService();
            $events = $calendarService->getWeekEvents($eventPlan, Carbon::parse($date));

            $weekStart = Carbon::parse($date)->startOfWeek();
            $weekEnd = Carbon::parse($date)->endOfWeek();

            return $this->successResponse([
                'week_start' => $weekStart->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'events' => $events,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get calendar week', [
                'event_plan_id' => $id,
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to get calendar week', 500);
        }
    }

    /**
     * Get calendar month view
     */
    public function calendarMonth(Request $request, string $id, int $year, int $month): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $calendarService = new CalendarService();
            $calendarData = $calendarService->getMonthCalendarData($eventPlan, $year, $month);

            return $this->successResponse($calendarData);
        } catch (\Exception $e) {
            Log::error('Failed to get calendar month', [
                'event_plan_id' => $id,
                'year' => $year,
                'month' => $month,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to get calendar month', 500);
        }
    }

    // =====================================
    // Recurring Pattern Methods
    // =====================================

    /**
     * List recurring patterns for an event plan
     */
    public function listRecurringPatterns(Request $request, string $id): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $patterns = RecurringEventPattern::where('event_plan_id', $id)
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->successResponse([
                'patterns' => $patterns,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list recurring patterns', [
                'event_plan_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to list recurring patterns', 500);
        }
    }

    /**
     * Create a recurring pattern
     */
    public function storeRecurringPattern(StoreRecurringPatternRequest $request, string $id): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $data = $request->validated();

            $pattern = RecurringEventPattern::create([
                'record_id' => Str::uuid()->toString(),
                'partition_id' => $eventPlan->partition_id,
                'event_plan_id' => $id,
                'name' => $data['name'],
                'recurrence_type' => $data['recurrence_type'],
                'interval_value' => $data['interval_value'] ?? 1,
                'days_of_week' => $data['days_of_week'] ?? null,
                'day_of_month' => $data['day_of_month'] ?? null,
                'week_of_month' => $data['week_of_month'] ?? null,
                'month_of_year' => $data['month_of_year'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'max_occurrences' => $data['max_occurrences'] ?? null,
                'event_template' => $data['event_template'] ?? null,
            ]);

            return $this->successResponse([
                'pattern' => $pattern,
                'message' => 'Recurring pattern created successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create recurring pattern', [
                'event_plan_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to create recurring pattern', 500);
        }
    }

    /**
     * Update a recurring pattern
     */
    public function updateRecurringPattern(Request $request, string $id, string $patternId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $pattern = RecurringEventPattern::where('event_plan_id', $id)
                ->where('record_id', $patternId)
                ->first();

            if (!$pattern) {
                return $this->errorResponse('Recurring pattern not found', 404);
            }

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'recurrence_type' => 'sometimes|string|in:none,daily,weekly,monthly,yearly,custom',
                'interval_value' => 'sometimes|integer|min:1|max:365',
                'days_of_week' => 'nullable|array',
                'days_of_week.*' => 'integer|min:0|max:6',
                'day_of_month' => 'nullable|integer|min:1|max:31',
                'week_of_month' => 'nullable|integer|min:1|max:5',
                'month_of_year' => 'nullable|integer|min:1|max:12',
                'start_date' => 'sometimes|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'max_occurrences' => 'nullable|integer|min:1|max:365',
                'event_template' => 'nullable|array',
            ]);

            $pattern->update($validated);

            return $this->successResponse([
                'pattern' => $pattern,
                'message' => 'Recurring pattern updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update recurring pattern', [
                'event_plan_id' => $id,
                'pattern_id' => $patternId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to update recurring pattern', 500);
        }
    }

    /**
     * Delete a recurring pattern
     */
    public function destroyRecurringPattern(Request $request, string $id, string $patternId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $pattern = RecurringEventPattern::where('event_plan_id', $id)
                ->where('record_id', $patternId)
                ->first();

            if (!$pattern) {
                return $this->errorResponse('Recurring pattern not found', 404);
            }

            $pattern->delete();

            return $this->successResponse([
                'message' => 'Recurring pattern deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete recurring pattern', [
                'event_plan_id' => $id,
                'pattern_id' => $patternId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to delete recurring pattern', 500);
        }
    }

    /**
     * Preview instances from a recurring pattern
     */
    public function previewPattern(Request $request, string $id, string $patternId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $pattern = RecurringEventPattern::where('event_plan_id', $id)
                ->where('record_id', $patternId)
                ->first();

            if (!$pattern) {
                return $this->errorResponse('Recurring pattern not found', 404);
            }

            $validated = $request->validate([
                'limit' => 'sometimes|integer|min:1|max:50',
                'start_from' => 'nullable|date',
            ]);

            $generatorService = new EventInstanceGeneratorService();
            $instances = $generatorService->previewInstances(
                $pattern,
                $validated['limit'] ?? 10,
                isset($validated['start_from']) ? Carbon::parse($validated['start_from']) : null
            );

            return $this->successResponse([
                'pattern' => $pattern,
                'instances' => $instances,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to preview pattern instances', [
                'event_plan_id' => $id,
                'pattern_id' => $patternId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to preview pattern instances', 500);
        }
    }

    /**
     * Generate events from a recurring pattern
     */
    public function generateFromPattern(Request $request, string $id, string $patternId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $pattern = RecurringEventPattern::where('event_plan_id', $id)
                ->where('record_id', $patternId)
                ->first();

            if (!$pattern) {
                return $this->errorResponse('Recurring pattern not found', 404);
            }

            $validated = $request->validate([
                'limit' => 'sometimes|integer|min:1|max:50',
                'start_from' => 'nullable|date',
                'add_to_canvas' => 'sometimes|boolean',
            ]);

            $generatorService = new EventInstanceGeneratorService();
            $events = $generatorService->generateFromPattern(
                $pattern,
                $validated['limit'] ?? 10,
                isset($validated['start_from']) ? Carbon::parse($validated['start_from']) : null,
                $validated['add_to_canvas'] ?? true
            );

            return $this->successResponse([
                'pattern' => $pattern,
                'generated_count' => count($events),
                'events' => $events,
                'message' => count($events) . ' events generated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate events from pattern', [
                'event_plan_id' => $id,
                'pattern_id' => $patternId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to generate events from pattern', 500);
        }
    }

    // =====================================
    // Graph/Canvas Methods
    // =====================================

    /**
     * Get graph data (nodes, connections, drawings)
     */
    public function getGraph(Request $request, string $id): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $data = [
                'nodes' => $eventPlan->nodes()->get(),
                'connections' => $eventPlan->connections()->get(),
                'drawings' => $eventPlan->drawings()->orderBy('z_index')->get(),
                'canvas_state' => $eventPlan->canvas_state,
            ];

            return $this->successResponse($data);
        } catch (\Exception $e) {
            Log::error('Failed to get graph data', [
                'event_plan_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to get graph data', 500);
        }
    }

    // =====================================
    // Node Methods
    // =====================================

    /**
     * Add a node to the canvas
     */
    public function storeNode(StoreNodeRequest $request, string $eventPlanId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $eventPlanId);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $data = $request->validated();

            // Check if entity already exists
            $existing = EventPlanNode::where('event_plan_id', $eventPlanId)
                ->where('entity_type', $data['entity_type'])
                ->where('entity_id', $data['entity_id'])
                ->first();

            if ($existing) {
                return $this->errorResponse('Entity already exists on canvas', 422);
            }

            $node = EventPlanNode::create([
                'record_id' => Str::uuid()->toString(),
                'partition_id' => $eventPlan->partition_id,
                'event_plan_id' => $eventPlanId,
                'entity_type' => $data['entity_type'],
                'entity_id' => $data['entity_id'],
                'x' => $data['x'] ?? 100,
                'y' => $data['y'] ?? 100,
                'width' => $data['width'] ?? 200,
                'height' => $data['height'] ?? 100,
                'z_index' => $data['z_index'] ?? 0,
                'style' => $data['style'] ?? null,
                'label_override' => $data['label_override'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_pinned' => $data['is_pinned'] ?? false,
                'is_collapsed' => $data['is_collapsed'] ?? false,
            ]);

            return $this->successResponse(['node' => $node], 201);
        } catch (\Exception $e) {
            Log::error('Failed to add node', [
                'event_plan_id' => $eventPlanId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to add node', 500);
        }
    }

    /**
     * Update a node
     */
    public function updateNode(Request $request, string $eventPlanId, string $nodeId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $eventPlanId);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $node = EventPlanNode::where('event_plan_id', $eventPlanId)
                ->where('record_id', $nodeId)
                ->first();

            if (!$node) {
                return $this->errorResponse('Node not found', 404);
            }

            $validated = $request->validate([
                'x' => 'sometimes|numeric',
                'y' => 'sometimes|numeric',
                'width' => 'sometimes|numeric|min:50',
                'height' => 'sometimes|numeric|min:30',
                'z_index' => 'sometimes|integer',
                'style' => 'nullable|array',
                'label_override' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'is_pinned' => 'sometimes|boolean',
                'is_collapsed' => 'sometimes|boolean',
            ]);

            $node->update($validated);

            return $this->successResponse(['node' => $node]);
        } catch (\Exception $e) {
            Log::error('Failed to update node', [
                'event_plan_id' => $eventPlanId,
                'node_id' => $nodeId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to update node', 500);
        }
    }

    /**
     * Delete a node
     */
    public function destroyNode(Request $request, string $eventPlanId, string $nodeId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $eventPlanId);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $node = EventPlanNode::where('event_plan_id', $eventPlanId)
                ->where('record_id', $nodeId)
                ->first();

            if (!$node) {
                return $this->errorResponse('Node not found', 404);
            }

            // Delete associated connections
            EventPlanConnection::where('event_plan_id', $eventPlanId)
                ->where(function ($q) use ($nodeId) {
                    $q->where('from_node_id', $nodeId)
                      ->orWhere('to_node_id', $nodeId);
                })
                ->delete();

            $node->delete();

            return $this->successResponse(['message' => 'Node deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to delete node', [
                'event_plan_id' => $eventPlanId,
                'node_id' => $nodeId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to delete node', 500);
        }
    }

    /**
     * Bulk add nodes
     */
    public function bulkAddNodes(Request $request, string $eventPlanId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $eventPlanId);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $validated = $request->validate([
                'nodes' => 'required|array|min:1|max:100',
                'nodes.*.entity_type' => 'required|string|in:person,place,event,note,task,file,entity,inventory_object,budget,invoice,tag,hypothesis,motive',
                'nodes.*.entity_id' => 'required|string|max:36',
                'nodes.*.x' => 'sometimes|numeric',
                'nodes.*.y' => 'sometimes|numeric',
            ]);

            $createdNodes = [];
            $startX = 100;
            $startY = 100;
            $offsetX = 250;
            $offsetY = 150;
            $index = 0;

            foreach ($validated['nodes'] as $nodeData) {
                // Skip if already exists
                $existing = EventPlanNode::where('event_plan_id', $eventPlanId)
                    ->where('entity_type', $nodeData['entity_type'])
                    ->where('entity_id', $nodeData['entity_id'])
                    ->exists();

                if ($existing) {
                    continue;
                }

                $row = floor($index / 4);
                $col = $index % 4;

                $node = EventPlanNode::create([
                    'record_id' => Str::uuid()->toString(),
                    'partition_id' => $eventPlan->partition_id,
                    'event_plan_id' => $eventPlanId,
                    'entity_type' => $nodeData['entity_type'],
                    'entity_id' => $nodeData['entity_id'],
                    'x' => $nodeData['x'] ?? ($startX + ($col * $offsetX)),
                    'y' => $nodeData['y'] ?? ($startY + ($row * $offsetY)),
                    'width' => 200,
                    'height' => 100,
                ]);

                $createdNodes[] = $node;
                $index++;
            }

            return $this->successResponse([
                'created' => count($createdNodes),
                'nodes' => $createdNodes,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to bulk add nodes', [
                'event_plan_id' => $eventPlanId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to bulk add nodes', 500);
        }
    }

    /**
     * Batch update node positions
     */
    public function batchUpdatePositions(Request $request, string $eventPlanId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $eventPlanId);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $validated = $request->validate([
                'positions' => 'required|array|min:1|max:500',
                'positions.*.node_id' => 'required|string|max:36',
                'positions.*.x' => 'required|numeric',
                'positions.*.y' => 'required|numeric',
            ]);

            DB::beginTransaction();

            foreach ($validated['positions'] as $position) {
                EventPlanNode::where('event_plan_id', $eventPlanId)
                    ->where('record_id', $position['node_id'])
                    ->update([
                        'x' => $position['x'],
                        'y' => $position['y'],
                    ]);
            }

            DB::commit();

            return $this->successResponse(['message' => 'Positions updated successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to batch update positions', [
                'event_plan_id' => $eventPlanId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to update positions', 500);
        }
    }

    // =====================================
    // Connection Methods
    // =====================================

    /**
     * Create a connection
     */
    public function storeConnection(StoreConnectionRequest $request, string $eventPlanId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $eventPlanId);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $data = $request->validated();

            $connection = EventPlanConnection::create([
                'record_id' => Str::uuid()->toString(),
                'partition_id' => $eventPlan->partition_id,
                'event_plan_id' => $eventPlanId,
                'from_node_id' => $data['from_node_id'],
                'to_node_id' => $data['to_node_id'],
                'from_side' => $data['from_side'] ?? 'right',
                'to_side' => $data['to_side'] ?? 'left',
                'style' => $data['style'] ?? 'solid',
                'path_type' => $data['path_type'] ?? 'curved',
                'color' => $data['color'] ?? '#6b7280',
                'thickness' => $data['thickness'] ?? 2,
                'arrow_type' => $data['arrow_type'] ?? 'none',
                'relationship_type' => $data['relationship_type'] ?? null,
                'relationship_label' => $data['relationship_label'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            return $this->successResponse(['connection' => $connection], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create connection', [
                'event_plan_id' => $eventPlanId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to create connection', 500);
        }
    }

    /**
     * Update a connection
     */
    public function updateConnection(Request $request, string $eventPlanId, string $connectionId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $eventPlanId);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $connection = EventPlanConnection::where('event_plan_id', $eventPlanId)
                ->where('record_id', $connectionId)
                ->first();

            if (!$connection) {
                return $this->errorResponse('Connection not found', 404);
            }

            $validated = $request->validate([
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
            ]);

            $connection->update($validated);

            return $this->successResponse(['connection' => $connection]);
        } catch (\Exception $e) {
            Log::error('Failed to update connection', [
                'event_plan_id' => $eventPlanId,
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to update connection', 500);
        }
    }

    /**
     * Delete a connection
     */
    public function destroyConnection(Request $request, string $eventPlanId, string $connectionId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $eventPlanId);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $connection = EventPlanConnection::where('event_plan_id', $eventPlanId)
                ->where('record_id', $connectionId)
                ->first();

            if (!$connection) {
                return $this->errorResponse('Connection not found', 404);
            }

            $connection->delete();

            return $this->successResponse(['message' => 'Connection deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to delete connection', [
                'event_plan_id' => $eventPlanId,
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to delete connection', 500);
        }
    }

    // =====================================
    // Drawing Methods
    // =====================================

    /**
     * Create a drawing
     */
    public function storeDrawing(Request $request, string $eventPlanId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $eventPlanId);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $validated = $request->validate([
                'tool' => 'required|string|in:pencil,line,rectangle,circle,arrow,text,highlighter',
                'points' => 'required|array',
                'color' => 'sometimes|string|max:7',
                'size' => 'sometimes|integer|min:1|max:50',
                'line_style' => 'nullable|string|in:solid,dashed,dotted',
                'thickness' => 'nullable|integer|min:1|max:20',
                'arrow_type' => 'nullable|string|max:10',
                'text' => 'nullable|string|max:500',
                'z_index' => 'sometimes|integer',
            ]);

            $drawing = EventPlanDrawing::create([
                'record_id' => Str::uuid()->toString(),
                'partition_id' => $eventPlan->partition_id,
                'event_plan_id' => $eventPlanId,
                'tool' => $validated['tool'],
                'points' => $validated['points'],
                'color' => $validated['color'] ?? '#000000',
                'size' => $validated['size'] ?? 2,
                'line_style' => $validated['line_style'] ?? null,
                'thickness' => $validated['thickness'] ?? null,
                'arrow_type' => $validated['arrow_type'] ?? null,
                'text' => $validated['text'] ?? null,
                'z_index' => $validated['z_index'] ?? 0,
            ]);

            return $this->successResponse(['drawing' => $drawing], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create drawing', [
                'event_plan_id' => $eventPlanId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to create drawing', 500);
        }
    }

    /**
     * Delete a drawing
     */
    public function destroyDrawing(Request $request, string $eventPlanId, string $drawingId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $eventPlanId);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $drawing = EventPlanDrawing::where('event_plan_id', $eventPlanId)
                ->where('record_id', $drawingId)
                ->first();

            if (!$drawing) {
                return $this->errorResponse('Drawing not found', 404);
            }

            $drawing->delete();

            return $this->successResponse(['message' => 'Drawing deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to delete drawing', [
                'event_plan_id' => $eventPlanId,
                'drawing_id' => $drawingId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to delete drawing', 500);
        }
    }

    // =====================================
    // Seat Plan Methods
    // =====================================

    /**
     * List seat plans for an event plan
     */
    public function listSeatPlans(Request $request, string $id): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $seatPlans = SeatPlan::where('event_plan_id', $id)
                ->with(['tables.assignments'])
                ->orderBy('created_at', 'desc')
                ->get();

            return $this->successResponse([
                'seat_plans' => $seatPlans,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list seat plans', [
                'event_plan_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to list seat plans', 500);
        }
    }

    /**
     * Create a seat plan
     */
    public function storeSeatPlan(StoreSeatPlanRequest $request, string $id): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $data = $request->validated();

            $seatPlan = SeatPlan::create([
                'record_id' => Str::uuid()->toString(),
                'partition_id' => $eventPlan->partition_id,
                'event_plan_id' => $id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'layout_type' => $data['layout_type'] ?? 'banquet',
                'canvas_state' => $data['canvas_state'] ?? null,
                'floor_plan_image_id' => $data['floor_plan_image_id'] ?? null,
            ]);

            return $this->successResponse([
                'seat_plan' => $seatPlan,
                'message' => 'Seat plan created successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create seat plan', [
                'event_plan_id' => $id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to create seat plan', 500);
        }
    }

    /**
     * Get a seat plan with tables and assignments
     */
    public function showSeatPlan(Request $request, string $id, string $planId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $seatPlan = SeatPlan::where('event_plan_id', $id)
                ->where('record_id', $planId)
                ->with(['tables.assignments.person', 'floorPlanImage'])
                ->first();

            if (!$seatPlan) {
                return $this->errorResponse('Seat plan not found', 404);
            }

            return $this->successResponse([
                'seat_plan' => $seatPlan,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get seat plan', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Seat plan not found', 404);
        }
    }

    /**
     * Update a seat plan
     */
    public function updateSeatPlan(UpdateSeatPlanRequest $request, string $id, string $planId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $seatPlan = SeatPlan::where('event_plan_id', $id)
                ->where('record_id', $planId)
                ->first();

            if (!$seatPlan) {
                return $this->errorResponse('Seat plan not found', 404);
            }

            $seatPlan->update($request->validated());

            return $this->successResponse([
                'seat_plan' => $seatPlan,
                'message' => 'Seat plan updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update seat plan', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to update seat plan', 500);
        }
    }

    /**
     * Delete a seat plan
     */
    public function destroySeatPlan(Request $request, string $id, string $planId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $seatPlan = SeatPlan::where('event_plan_id', $id)
                ->where('record_id', $planId)
                ->first();

            if (!$seatPlan) {
                return $this->errorResponse('Seat plan not found', 404);
            }

            $seatPlan->delete();

            return $this->successResponse([
                'message' => 'Seat plan deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete seat plan', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to delete seat plan', 500);
        }
    }

    // =====================================
    // Seat Table Methods
    // =====================================

    /**
     * Add a table to a seat plan
     */
    public function storeSeatTable(StoreSeatTableRequest $request, string $id, string $planId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $seatPlan = SeatPlan::where('event_plan_id', $id)
                ->where('record_id', $planId)
                ->first();

            if (!$seatPlan) {
                return $this->errorResponse('Seat plan not found', 404);
            }

            $data = $request->validated();

            // Get next table number if not provided
            if (empty($data['table_number'])) {
                $maxNumber = $seatPlan->tables()->max('table_number') ?? 0;
                $data['table_number'] = $maxNumber + 1;
            }

            $table = SeatTable::create([
                'record_id' => Str::uuid()->toString(),
                'partition_id' => $seatPlan->partition_id,
                'seat_plan_id' => $planId,
                'name' => $data['name'],
                'table_number' => $data['table_number'],
                'table_type' => $data['table_type'] ?? 'round',
                'capacity' => $data['capacity'] ?? SeatTable::DEFAULT_CAPACITIES[$data['table_type'] ?? 'round'],
                'x' => $data['x'] ?? 100,
                'y' => $data['y'] ?? 100,
                'rotation' => $data['rotation'] ?? 0,
                'width' => $data['width'] ?? SeatTable::DEFAULT_DIMENSIONS[$data['table_type'] ?? 'round']['width'],
                'height' => $data['height'] ?? SeatTable::DEFAULT_DIMENSIONS[$data['table_type'] ?? 'round']['height'],
                'color' => $data['color'] ?? '#e5e7eb',
                'notes' => $data['notes'] ?? null,
            ]);

            return $this->successResponse([
                'table' => $table,
                'message' => 'Table added successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to add table', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to add table', 500);
        }
    }

    /**
     * Update a table
     */
    public function updateSeatTable(UpdateSeatTableRequest $request, string $id, string $planId, string $tableId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $table = SeatTable::where('seat_plan_id', $planId)
                ->where('record_id', $tableId)
                ->first();

            if (!$table) {
                return $this->errorResponse('Table not found', 404);
            }

            $table->update($request->validated());

            return $this->successResponse([
                'table' => $table,
                'message' => 'Table updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update table', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'table_id' => $tableId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to update table', 500);
        }
    }

    /**
     * Delete a table
     */
    public function destroySeatTable(Request $request, string $id, string $planId, string $tableId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $table = SeatTable::where('seat_plan_id', $planId)
                ->where('record_id', $tableId)
                ->first();

            if (!$table) {
                return $this->errorResponse('Table not found', 404);
            }

            $table->delete();

            return $this->successResponse([
                'message' => 'Table deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete table', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'table_id' => $tableId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to delete table', 500);
        }
    }

    /**
     * Batch update table positions
     */
    public function batchUpdateTablePositions(Request $request, string $id, string $planId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $validated = $request->validate([
                'positions' => 'required|array|min:1|max:500',
                'positions.*.table_id' => 'required|string|max:36',
                'positions.*.x' => 'required|numeric',
                'positions.*.y' => 'required|numeric',
                'positions.*.rotation' => 'sometimes|numeric',
            ]);

            DB::beginTransaction();

            foreach ($validated['positions'] as $position) {
                $updateData = [
                    'x' => $position['x'],
                    'y' => $position['y'],
                ];

                if (isset($position['rotation'])) {
                    $updateData['rotation'] = $position['rotation'];
                }

                SeatTable::where('seat_plan_id', $planId)
                    ->where('record_id', $position['table_id'])
                    ->update($updateData);
            }

            DB::commit();

            return $this->successResponse(['message' => 'Table positions updated successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to batch update table positions', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to update table positions', 500);
        }
    }

    // =====================================
    // Seat Assignment Methods
    // =====================================

    /**
     * Assign a guest to a seat
     */
    public function storeSeatAssignment(StoreSeatAssignmentRequest $request, string $id, string $planId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $seatPlan = SeatPlan::where('event_plan_id', $id)
                ->where('record_id', $planId)
                ->first();

            if (!$seatPlan) {
                return $this->errorResponse('Seat plan not found', 404);
            }

            $data = $request->validated();

            // Verify table exists and belongs to this seat plan
            $table = SeatTable::where('seat_plan_id', $planId)
                ->where('record_id', $data['seat_table_id'])
                ->firstOrFail();

            // Check if seat is already taken
            $existingAssignment = SeatAssignment::where('seat_table_id', $data['seat_table_id'])
                ->where('seat_number', $data['seat_number'])
                ->first();

            if ($existingAssignment) {
                return $this->errorResponse('Seat is already assigned', 422);
            }

            // Check if seat number is valid
            if ($data['seat_number'] > $table->capacity) {
                return $this->errorResponse('Seat number exceeds table capacity', 422);
            }

            $assignment = SeatAssignment::create([
                'record_id' => Str::uuid()->toString(),
                'partition_id' => $seatPlan->partition_id,
                'seat_table_id' => $data['seat_table_id'],
                'person_id' => $data['person_id'] ?? null,
                'guest_name' => $data['guest_name'] ?? null,
                'guest_email' => $data['guest_email'] ?? null,
                'seat_number' => $data['seat_number'],
                'dietary_requirements' => $data['dietary_requirements'] ?? null,
                'accessibility_needs' => $data['accessibility_needs'] ?? null,
                'notes' => $data['notes'] ?? null,
                'rsvp_status' => $data['rsvp_status'] ?? 'pending',
            ]);

            $assignment->load('person');

            return $this->successResponse([
                'assignment' => $assignment,
                'message' => 'Guest assigned successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to assign guest', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to assign guest', 500);
        }
    }

    /**
     * Update a seat assignment
     */
    public function updateSeatAssignment(UpdateSeatAssignmentRequest $request, string $id, string $planId, string $assignmentId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $assignment = SeatAssignment::where('record_id', $assignmentId)
                ->whereHas('seatTable', function ($q) use ($planId) {
                    $q->where('seat_plan_id', $planId);
                })
                ->first();

            if (!$assignment) {
                return $this->errorResponse('Assignment not found', 404);
            }

            $data = $request->validated();

            // If changing seat, validate it's available
            if (isset($data['seat_number']) && $data['seat_number'] !== $assignment->seat_number) {
                $tableId = $data['seat_table_id'] ?? $assignment->seat_table_id;

                $existing = SeatAssignment::where('seat_table_id', $tableId)
                    ->where('seat_number', $data['seat_number'])
                    ->where('record_id', '!=', $assignmentId)
                    ->first();

                if ($existing) {
                    return $this->errorResponse('Seat is already assigned', 422);
                }
            }

            $assignment->update($data);
            $assignment->load('person');

            return $this->successResponse([
                'assignment' => $assignment,
                'message' => 'Assignment updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update assignment', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to update assignment', 500);
        }
    }

    /**
     * Remove a seat assignment
     */
    public function destroySeatAssignment(Request $request, string $id, string $planId, string $assignmentId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $assignment = SeatAssignment::where('record_id', $assignmentId)
                ->whereHas('seatTable', function ($q) use ($planId) {
                    $q->where('seat_plan_id', $planId);
                })
                ->first();

            if (!$assignment) {
                return $this->errorResponse('Assignment not found', 404);
            }

            $assignment->delete();

            return $this->successResponse([
                'message' => 'Assignment removed successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to remove assignment', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to remove assignment', 500);
        }
    }

    /**
     * Update seat position (for drag and drop)
     */
    public function updateSeatPosition(Request $request, string $id, string $planId, string $assignmentId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $assignment = SeatAssignment::where('record_id', $assignmentId)
                ->whereHas('seatTable', function ($q) use ($planId) {
                    $q->where('seat_plan_id', $planId);
                })
                ->first();

            if (!$assignment) {
                return $this->errorResponse('Assignment not found', 404);
            }

            $validated = $request->validate([
                'seat_x' => 'required|numeric',
                'seat_y' => 'required|numeric',
            ]);

            $assignment->update([
                'seat_x' => $validated['seat_x'],
                'seat_y' => $validated['seat_y'],
                'custom_position' => true,
            ]);

            return $this->successResponse([
                'assignment' => $assignment,
                'message' => 'Seat position updated',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // Let Laravel handle validation errors (returns 422)
        } catch (\Exception $e) {
            Log::error('Failed to update seat position', [
                'event_plan_id' => $id,
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to update seat position', 500);
        }
    }

    /**
     * Reset seat position to preset
     */
    public function resetSeatPosition(Request $request, string $id, string $planId, string $assignmentId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $assignment = SeatAssignment::where('record_id', $assignmentId)
                ->whereHas('seatTable', function ($q) use ($planId) {
                    $q->where('seat_plan_id', $planId);
                })
                ->first();

            if (!$assignment) {
                return $this->errorResponse('Assignment not found', 404);
            }

            $assignment->update([
                'seat_x' => null,
                'seat_y' => null,
                'custom_position' => false,
            ]);

            return $this->successResponse([
                'assignment' => $assignment,
                'message' => 'Seat position reset to preset',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reset seat position', [
                'event_plan_id' => $id,
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to reset seat position', 500);
        }
    }

    /**
     * Update seat position on table (for any seat, assigned or not)
     */
    public function updateTableSeatPosition(Request $request, string $id, string $planId, string $tableId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $table = SeatTable::where('record_id', $tableId)
                ->where('seat_plan_id', $planId)
                ->first();

            if (!$table) {
                return $this->errorResponse('Table not found', 404);
            }

            $validated = $request->validate([
                'seat_number' => 'required|integer|min:1|max:' . $table->capacity,
                'x' => 'required|numeric',
                'y' => 'required|numeric',
            ]);

            $table->setSeatPosition(
                $validated['seat_number'],
                $validated['x'],
                $validated['y']
            );

            // Reload table with assignments
            $table->load('assignments.person');

            return $this->successResponse([
                'table' => $table,
                'message' => 'Seat position updated',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update table seat position', [
                'event_plan_id' => $id,
                'table_id' => $tableId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to update seat position', 500);
        }
    }

    /**
     * Reset all seat positions for a table
     */
    public function resetTableSeatPositions(Request $request, string $id, string $planId, string $tableId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $table = SeatTable::where('record_id', $tableId)
                ->where('seat_plan_id', $planId)
                ->first();

            if (!$table) {
                return $this->errorResponse('Table not found', 404);
            }

            $table->resetAllSeatPositions();

            // Reload table with assignments
            $table->load('assignments.person');

            return $this->successResponse([
                'table' => $table,
                'message' => 'All seat positions reset to default',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reset table seat positions', [
                'event_plan_id' => $id,
                'table_id' => $tableId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to reset seat positions', 500);
        }
    }

    /**
     * Get table with resolved seat positions
     */
    public function getTableWithSeats(Request $request, string $id, string $planId, string $tableId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $table = SeatTable::where('record_id', $tableId)
                ->where('seat_plan_id', $planId)
                ->with('assignments.person')
                ->first();

            if (!$table) {
                return $this->errorResponse('Table not found', 404);
            }

            $layoutService = new SeatPlanLayoutService();
            $seatPositions = $layoutService->getResolvedSeatPositions($table);

            return $this->successResponse([
                'table' => $table,
                'seat_positions' => $seatPositions,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get table with seats', [
                'event_plan_id' => $id,
                'table_id' => $tableId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to get table seats', 500);
        }
    }

    /**
     * Apply preset layout to all seats on a table
     */
    public function applyPresetPositions(Request $request, string $id, string $planId, string $tableId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $table = SeatTable::where('record_id', $tableId)
                ->where('seat_plan_id', $planId)
                ->with('assignments')
                ->first();

            if (!$table) {
                return $this->errorResponse('Table not found', 404);
            }

            $layoutService = new SeatPlanLayoutService();
            $layoutService->applyPresetPositions($table);

            // Refresh to get updated positions
            $table->refresh();
            $table->load('assignments.person');
            $seatPositions = $layoutService->getResolvedSeatPositions($table);

            return $this->successResponse([
                'table' => $table,
                'seat_positions' => $seatPositions,
                'message' => 'Preset positions applied',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to apply preset positions', [
                'event_plan_id' => $id,
                'table_id' => $tableId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to apply preset positions', 500);
        }
    }

    /**
     * Bulk assign guests to seats
     */
    public function bulkAssignSeats(Request $request, string $id, string $planId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $seatPlan = SeatPlan::where('event_plan_id', $id)
                ->where('record_id', $planId)
                ->first();

            if (!$seatPlan) {
                return $this->errorResponse('Seat plan not found', 404);
            }

            $validated = $request->validate([
                'assignments' => 'required|array|min:1|max:500',
                'assignments.*.seat_table_id' => 'required|string|max:36',
                'assignments.*.seat_number' => 'required|integer|min:1',
                'assignments.*.person_id' => 'nullable|string|max:36',
                'assignments.*.guest_name' => 'nullable|string|max:255',
                'assignments.*.rsvp_status' => 'nullable|string|in:pending,confirmed,declined,tentative',
            ]);

            DB::beginTransaction();

            $created = [];
            $errors = [];

            foreach ($validated['assignments'] as $assignmentData) {
                // Check if seat is available
                $existing = SeatAssignment::where('seat_table_id', $assignmentData['seat_table_id'])
                    ->where('seat_number', $assignmentData['seat_number'])
                    ->first();

                if ($existing) {
                    $errors[] = "Seat {$assignmentData['seat_number']} at table is already assigned";
                    continue;
                }

                $assignment = SeatAssignment::create([
                    'record_id' => Str::uuid()->toString(),
                    'partition_id' => $seatPlan->partition_id,
                    'seat_table_id' => $assignmentData['seat_table_id'],
                    'person_id' => $assignmentData['person_id'] ?? null,
                    'guest_name' => $assignmentData['guest_name'] ?? null,
                    'seat_number' => $assignmentData['seat_number'],
                    'rsvp_status' => $assignmentData['rsvp_status'] ?? 'pending',
                ]);

                $created[] = $assignment;
            }

            DB::commit();

            return $this->successResponse([
                'created_count' => count($created),
                'assignments' => $created,
                'errors' => $errors,
                'message' => count($created) . ' guests assigned successfully',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to bulk assign guests', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to bulk assign guests', 500);
        }
    }

    /**
     * Auto-assign guests to seats
     */
    public function autoAssignSeats(Request $request, string $id, string $planId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $seatPlan = SeatPlan::where('event_plan_id', $id)
                ->where('record_id', $planId)
                ->first();

            if (!$seatPlan) {
                return $this->errorResponse('Seat plan not found', 404);
            }

            $validated = $request->validate([
                'guest_ids' => 'required|array|min:1',
                'guest_ids.*' => 'string|max:36',
                'by_person' => 'sometimes|boolean',
            ]);

            $layoutService = new SeatPlanLayoutService();
            $result = $layoutService->autoAssignGuests(
                $seatPlan,
                $validated['guest_ids'],
                $seatPlan->partition_id,
                $validated['by_person'] ?? true
            );

            return $this->successResponse([
                'assigned' => $result['assigned'],
                'remaining' => $result['remaining'],
                'assignments' => $result['assignments'],
                'message' => "{$result['assigned']} guests assigned, {$result['remaining']} remaining",
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to auto-assign guests', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to auto-assign guests', 500);
        }
    }

    /**
     * Check in a guest
     */
    public function checkInGuest(Request $request, string $id, string $planId, string $assignmentId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $assignment = SeatAssignment::where('record_id', $assignmentId)
                ->whereHas('seatTable', function ($q) use ($planId) {
                    $q->where('seat_plan_id', $planId);
                })
                ->first();

            if (!$assignment) {
                return $this->errorResponse('Assignment not found', 404);
            }

            $assignment->checkIn();

            return $this->successResponse([
                'assignment' => $assignment,
                'message' => 'Guest checked in successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to check in guest', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to check in guest', 500);
        }
    }

    /**
     * Undo check-in for a guest
     */
    public function undoCheckIn(Request $request, string $id, string $planId, string $assignmentId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $assignment = SeatAssignment::where('record_id', $assignmentId)
                ->whereHas('seatTable', function ($q) use ($planId) {
                    $q->where('seat_plan_id', $planId);
                })
                ->first();

            if (!$assignment) {
                return $this->errorResponse('Assignment not found', 404);
            }

            $assignment->undoCheckIn();

            return $this->successResponse([
                'assignment' => $assignment,
                'message' => 'Check-in undone successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to undo check-in', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'assignment_id' => $assignmentId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to undo check-in', 500);
        }
    }

    /**
     * Get seating statistics
     */
    public function seatPlanStats(Request $request, string $id, string $planId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $seatPlan = SeatPlan::where('event_plan_id', $id)
                ->where('record_id', $planId)
                ->first();

            if (!$seatPlan) {
                return $this->errorResponse('Seat plan not found', 404);
            }

            $layoutService = new SeatPlanLayoutService();
            $stats = $layoutService->calculateStats($seatPlan);

            return $this->successResponse($stats);
        } catch (\Exception $e) {
            Log::error('Failed to get seat plan stats', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to get statistics', 500);
        }
    }

    /**
     * Auto-arrange tables based on layout type
     */
    public function autoArrangeTables(Request $request, string $id, string $planId): JsonResponse
    {
        try {
            $eventPlan = $this->getEventPlanWithPartition($request, $id);
            if (!$eventPlan) {
                return $this->errorResponse('Event plan not found', 404);
            }

            $seatPlan = SeatPlan::where('event_plan_id', $id)
                ->where('record_id', $planId)
                ->first();

            if (!$seatPlan) {
                return $this->errorResponse('Seat plan not found', 404);
            }

            $layoutService = new SeatPlanLayoutService();
            $layoutService->autoArrangeTables($seatPlan);

            $seatPlan->load('tables');

            return $this->successResponse([
                'seat_plan' => $seatPlan,
                'message' => 'Tables arranged successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to auto-arrange tables', [
                'event_plan_id' => $id,
                'seat_plan_id' => $planId,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to arrange tables', 500);
        }
    }

    /**
     * Find entity by ID for relationship management.
     * SECURITY: Validates partition access to prevent IDOR attacks.
     */
    protected function findEntity(string $id): ?EventPlan
    {
        $entity = EventPlan::find($id);

        if (! $entity) {
            return null;
        }

        // SECURITY: Validate entity belongs to user's accessible partitions
        try {
            $user = $this->getAuthenticatedUser(request());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return null; // Unauthenticated - prevent information disclosure
        }
        if (! $this->canAccessEntityPartition($entity, $user)) {
            return null; // Return null to prevent information disclosure
        }

        return $entity;
    }

    /**
     * Get an event plan with partition verification and access control
     * Returns null if not found, partition mismatch, or no access
     */
    protected function getEventPlanWithPartition(Request $request, string $id): ?EventPlan
    {
        $partitionId = $request->header('X-Partition-ID');
        $user = $this->getAuthenticatedUser($request);

        // Require partition ID for non-system users
        if (!$partitionId && !$user->is_system_user) {
            return null;
        }

        // Build query - system admins can access any event plan
        $query = EventPlan::where('record_id', $id);
        // System admins see all; others are filtered by partition
        if (!$user->is_system_user) {
            $query->where('partition_id', $partitionId);
        }

        $eventPlan = $query->first();

        if (!$eventPlan) {
            return null;
        }

        // Check access control - system admins bypass this
        if (!$user->is_system_user && !$this->canAccessEventPlan($eventPlan, $user, $partitionId ?? $eventPlan->partition_id)) {
            return null;
        }

        return $eventPlan;
    }

    /**
     * Escape special characters for LIKE patterns to prevent pattern injection.
     * This prevents users from using % or _ as wildcards in search terms.
     */
    protected function escapeLikePattern(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }

    /**
     * Apply user-level access control to an event plan query.
     * Filters to show only: user's own plans, public plans, and shared plans.
     * System admins and partition admins can see all plans.
     */
    protected function applyAccessControl($query, IdentityUserContract $user, string $partitionId): void
    {
        // System admins and partition admins can see all event plans in the partition
        if ($user->is_system_user || $user->isPartitionAdmin($partitionId)) {
            return;
        }

        // Regular users: filter to own + public + shared event plans
        $query->where(function ($q) use ($user) {
            // Own event plans
            $q->where('created_by', $user->record_id);

            // Public event plans
            $q->orWhere('is_public', true);

            // Shared event plans (via record_shares table)
            $q->orWhereExists(function ($subQuery) use ($user) {
                $subQuery->select(DB::raw(1))
                    ->from('record_shares')
                    ->whereColumn('record_shares.shareable_id', 'event_plans.record_id')
                    ->where('record_shares.shareable_type', EventPlan::class)
                    ->where('record_shares.shared_with_user_id', $user->record_id)
                    ->where('record_shares.deleted', false)
                    ->where(function ($expQ) {
                        $expQ->whereNull('record_shares.expires_at')
                            ->orWhere('record_shares.expires_at', '>', now());
                    });
            });
        });
    }

    /**
     * Check if user can access (read) an event plan.
     */
    protected function canAccessEventPlan(EventPlan $eventPlan, IdentityUserContract $user, string $partitionId): bool
    {
        // System admins and partition admins can access all
        if ($user->is_system_user || $user->isPartitionAdmin($partitionId)) {
            return true;
        }

        // Use the model's canAccess method
        return $eventPlan->canAccess($user->record_id);
    }

    /**
     * Check if user can write (update/delete) an event plan.
     * Only creators and admins can write.
     */
    protected function canWriteEventPlan(EventPlan $eventPlan, IdentityUserContract $user, string $partitionId): bool
    {
        // System admins and partition admins can write all
        if ($user->is_system_user || $user->isPartitionAdmin($partitionId)) {
            return true;
        }

        // Only creator can write
        return $eventPlan->created_by === $user->record_id;
    }
}
