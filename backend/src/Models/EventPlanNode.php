<?php

namespace NewSolari\EventPlanning\Models;

use NewSolari\Core\Entity\BaseEntity;
use NewSolari\Core\Entity\Models\EntityTypeRegistry;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventPlanNode extends BaseEntity
{
    protected $table = 'event_plan_nodes';
    protected $primaryKey = 'record_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'record_id',
        'partition_id',
        'event_plan_id',
        'entity_type',
        'entity_id',
        'x',
        'y',
        'width',
        'height',
        'z_index',
        'style',
        'label_override',
        'notes',
        'is_pinned',
        'is_collapsed',
    ];

    protected $casts = [
        'x' => 'decimal:2',
        'y' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'z_index' => 'integer',
        'style' => 'array',
        'is_pinned' => 'boolean',
        'is_collapsed' => 'boolean',
    ];

    protected $validations = [
        'record_id' => 'nullable|string|max:36',
        'partition_id' => 'sometimes|string|max:36|exists:identity_partitions,record_id',
        'event_plan_id' => 'required|string|max:36|exists:event_plans,record_id',
        'entity_type' => 'required|string|max:50',
        'entity_id' => 'required|string|max:36',
        'x' => 'required|numeric',
        'y' => 'required|numeric',
        'width' => 'nullable|numeric|min:0',
        'height' => 'nullable|numeric|min:0',
        'z_index' => 'nullable|integer|min:0',
        'style' => 'nullable|array',
        'label_override' => 'nullable|string|max:255',
        'notes' => 'nullable|string',
        'is_pinned' => 'boolean',
        'is_collapsed' => 'boolean',
    ];

    protected $appends = ['display_label', 'resolved_entity'];

    /**
     * Default dimensions for nodes
     */
    public const DEFAULT_DIMENSIONS = [
        'width' => 200,
        'height' => 100,
    ];

    /**
     * Linkable entity types
     */
    public const ENTITY_TYPES = [
        'person',
        'place',
        'event',
        'note',
        'task',
        'file',
        'entity',
        'inventory_object',
        'budget',
        'invoice',
        'tag',
        'hypothesis',
        'motive',
    ];

    /**
     * Get the parent event plan
     */
    public function eventPlan(): BelongsTo
    {
        return $this->belongsTo(EventPlan::class, 'event_plan_id', 'record_id');
    }

    /**
     * Get outgoing connections
     */
    public function fromConnections(): HasMany
    {
        return $this->hasMany(EventPlanConnection::class, 'from_node_id', 'record_id');
    }

    /**
     * Get incoming connections
     */
    public function toConnections(): HasMany
    {
        return $this->hasMany(EventPlanConnection::class, 'to_node_id', 'record_id');
    }

    /**
     * Resolve the linked entity
     */
    public function resolveEntity(): ?object
    {
        try {
            $registry = app(EntityTypeRegistry::class);
            return $registry->resolveEntity($this->entity_type, $this->entity_id);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get resolved entity attribute
     */
    public function getResolvedEntityAttribute(): ?array
    {
        $entity = $this->resolveEntity();
        if (!$entity) {
            return null;
        }

        // Return basic entity info
        return [
            'record_id' => $entity->record_id ?? $entity->id ?? null,
            'name' => $this->getEntityDisplayName($entity),
            'type' => $this->entity_type,
        ];
    }

    /**
     * Get display label attribute
     */
    public function getDisplayLabelAttribute(): string
    {
        if ($this->label_override) {
            return $this->label_override;
        }

        $entity = $this->resolveEntity();
        if ($entity) {
            return $this->getEntityDisplayName($entity);
        }

        return ucfirst($this->entity_type) . ' (Unknown)';
    }

    /**
     * Get entity display name
     */
    protected function getEntityDisplayName(object $entity): string
    {
        // Try common name fields
        $nameFields = ['name', 'title', 'display_name', 'full_name', 'first_name', 'invoice_number'];

        foreach ($nameFields as $field) {
            if (isset($entity->$field) && $entity->$field) {
                return (string) $entity->$field;
            }
        }

        return ucfirst($this->entity_type) . ' #' . substr($this->entity_id, 0, 8);
    }

    /**
     * Update position
     */
    public function updatePosition(float $x, float $y): void
    {
        $this->x = $x;
        $this->y = $y;
        $this->save();
    }

    /**
     * Update dimensions
     */
    public function updateDimensions(float $width, float $height): void
    {
        $this->width = $width;
        $this->height = $height;
        $this->save();
    }
}
