<?php

namespace NewSolari\EventPlanning\Models;

use NewSolari\Core\Entity\BaseEntity;
use NewSolari\Core\Entity\Traits\HasUnifiedRelationships;
use NewSolari\Core\Entity\Traits\Shareable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NewSolari\Budgets\Models\Budget;
use NewSolari\Core\Identity\Models\IdentityUser;

class EventPlan extends BaseEntity
{
    use HasUnifiedRelationships, Shareable;

    /**
     * Relationships to cascade soft delete.
     */
    protected static array $cascadeOnDelete = ['nodes', 'connections', 'drawings', 'recurringPatterns'];

    protected $table = 'event_plans';
    protected $primaryKey = 'record_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'record_id',
        'partition_id',
        'title',
        'description',
        'event_type',
        'status',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'timezone',
        'venue_place_id',
        'organizer_entity_id',
        'expected_guests',
        'budget_id',
        'canvas_state',
        'default_view',
        'is_public',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'canvas_state' => 'array',
        'expected_guests' => 'integer',
        'is_public' => 'boolean',
    ];

    protected $validations = [
        'record_id' => 'nullable|string|max:36',
        'partition_id' => 'sometimes|string|max:36|exists:identity_partitions,record_id',
        'title' => 'required|string|max:255',
        'description' => 'nullable|string',
        'event_type' => 'nullable|string|in:wedding,conference,party,corporate,birthday,anniversary,meeting,workshop,seminar,reception,gala,other',
        'status' => 'nullable|string|in:draft,planning,active,on_hold,completed,cancelled',
        'start_date' => 'nullable|date',
        'end_date' => 'nullable|date|after_or_equal:start_date',
        'start_time' => 'nullable|string|max:8',
        'end_time' => 'nullable|string|max:8',
        'timezone' => 'nullable|string|max:50',
        'venue_place_id' => 'nullable|string|max:36|exists:places,record_id',
        'organizer_entity_id' => 'nullable|string|max:36|exists:entities,record_id',
        'expected_guests' => 'nullable|integer|min:0',
        'budget_id' => 'nullable|string|max:36|exists:budgets,record_id',
        'canvas_state' => 'nullable|array',
        'default_view' => 'nullable|string|max:50',
        'is_public' => 'boolean',
        'notes' => 'nullable|string',
        'created_by' => 'sometimes|string|max:36|exists:identity_users,record_id',
        'updated_by' => 'nullable|string|max:36|exists:identity_users,record_id',
    ];

    /**
     * Event types
     */
    public const EVENT_TYPES = [
        'wedding' => 'Wedding',
        'conference' => 'Conference',
        'party' => 'Party',
        'corporate' => 'Corporate Event',
        'birthday' => 'Birthday',
        'anniversary' => 'Anniversary',
        'meeting' => 'Meeting',
        'workshop' => 'Workshop',
        'seminar' => 'Seminar',
        'reception' => 'Reception',
        'gala' => 'Gala',
        'other' => 'Other',
    ];

    /**
     * Status options
     */
    public const STATUSES = [
        'draft' => 'Draft',
        'planning' => 'Planning',
        'active' => 'Active',
        'on_hold' => 'On Hold',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    /**
     * Get canvas nodes
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(EventPlanNode::class, 'event_plan_id', 'record_id');
    }

    /**
     * Get canvas connections
     */
    public function connections(): HasMany
    {
        return $this->hasMany(EventPlanConnection::class, 'event_plan_id', 'record_id');
    }

    /**
     * Get canvas drawings
     */
    public function drawings(): HasMany
    {
        return $this->hasMany(EventPlanDrawing::class, 'event_plan_id', 'record_id');
    }

    /**
     * Get recurring patterns
     */
    public function recurringPatterns(): HasMany
    {
        return $this->hasMany(RecurringEventPattern::class, 'event_plan_id', 'record_id');
    }

    /**
     * Get linked budget
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id', 'record_id');
    }

    /**
     * Get creator user
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'created_by', 'record_id');
    }

    /**
     * Get updater user
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'updated_by', 'record_id');
    }

    /**
     * Update canvas state
     */
    public function updateCanvasState(array $state): void
    {
        $this->canvas_state = array_merge($this->canvas_state ?? [], $state);
        $this->save();
    }

    /**
     * Get graph data (nodes, connections, drawings)
     */
    public function getGraphData(): array
    {
        return [
            'nodes' => $this->nodes()->get(),
            'connections' => $this->connections()->get(),
            'drawings' => $this->drawings()->get(),
        ];
    }

    /**
     * Check if user can access this event plan
     */
    public function canAccess(string $userId): bool
    {
        if ($this->created_by === $userId) {
            return true;
        }

        if ($this->is_public) {
            return true;
        }

        // Check shares
        return $this->shares()
            ->where('shared_with_user_id', $userId)
            ->exists();
    }

    /**
     * Get source plugin ID
     */
    public function getSourcePluginId(): string
    {
        return 'event-planning-meta-app';
    }
}
