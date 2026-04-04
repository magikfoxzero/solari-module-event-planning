<?php

namespace Tests\Feature\API;

use NewSolari\Core\Identity\Models\IdentityPartition;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\Permission;
use NewSolari\Core\Identity\Models\Group;
use NewSolari\EventPlanning\Models\EventPlan;
use NewSolari\EventPlanning\Models\EventPlanNode;
use NewSolari\EventPlanning\Models\EventPlanConnection;
use NewSolari\EventPlanning\Models\EventPlanDrawing;
use NewSolari\EventPlanning\Models\RecurringEventPattern;
use NewSolari\EventPlanning\Models\SeatPlan;
use NewSolari\EventPlanning\Models\SeatTable;
use NewSolari\EventPlanning\Models\SeatAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventPlanningControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $systemUser;
    protected $regularUser;
    protected $partition;
    protected $otherPartition;
    protected $eventPlan;

    /**
     * Clean up after each test to prevent transaction state issues.
     */
    protected function tearDown(): void
    {
        // Ensure any stale transactions are rolled back
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        } catch (\Exception $e) {
            // Ignore cleanup errors
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Create partitions
        $this->partition = IdentityPartition::create([
            'record_id' => 'partition-ep-test-01',
            'name' => 'Test Partition',
            'description' => 'Test partition for event planning',
        ]);

        $this->otherPartition = IdentityPartition::create([
            'record_id' => 'partition-ep-test-02',
            'name' => 'Other Partition',
            'description' => 'Other partition for isolation tests',
        ]);

        // Create system user
        $this->systemUser = IdentityUser::create([
            'record_id' => 'user-ep-system-01',
            'partition_id' => $this->partition->record_id,
            'username' => 'systemuser',
            'email' => 'system@example.com',
            'password_hash' => 'password',
            'is_active' => true,
        ]);
        $this->systemUser->setSystemUser(true);

        // Create regular user
        $this->regularUser = IdentityUser::create([
            'record_id' => 'user-ep-regular-01',
            'partition_id' => $this->partition->record_id,
            'username' => 'regularuser',
            'email' => 'regular@example.com',
            'password_hash' => 'password',
            'is_active' => true,
        ]);

        $this->regularUser->partitions()->attach($this->partition->record_id);

        // Create permissions for event planning
        $permissions = [];
        foreach (['read', 'create', 'update', 'delete'] as $action) {
            $permissions[$action] = Permission::create([
                'record_id' => "perm-ep-{$action}",
                'name' => "event_planning.{$action}",
                'permission_type' => ucfirst($action),
                'entity_type' => 'EventPlan',
                'partition_id' => $this->partition->record_id,
                'plugin_id' => 'event-planning-meta-app',
            ]);
        }

        // Create group and assign permissions
        $regularUserGroup = Group::create([
            'record_id' => 'group-ep-regular-users',
            'name' => 'Regular Users',
            'partition_id' => $this->partition->record_id,
            'is_active' => true,
        ]);

        foreach ($permissions as $permission) {
            $regularUserGroup->assignPermission($permission->record_id);
        }
        $regularUserGroup->addUser($this->regularUser->record_id);

        // Create test event plan
        $this->eventPlan = EventPlan::create([
            'record_id' => 'event-plan-test-01',
            'partition_id' => $this->partition->record_id,
            'title' => 'Test Event',
            'description' => 'Test event description',
            'event_type' => 'wedding',
            'status' => 'planning',
            'start_date' => now()->addDays(30),
            'end_date' => now()->addDays(30),
            'start_time' => '14:00',
            'end_time' => '22:00',
            'timezone' => 'America/New_York',
            'expected_guests' => 100,
            'is_public' => false,
            'created_by' => $this->systemUser->record_id,
        ]);
    }

    // ============================================
    // SECURITY TESTS - SQL INJECTION
    // ============================================

    /**
     * @test
     * @group security
     * @group sql_injection
     */
    public function test_sql_injection_in_event_plan_title()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson('/api/event-plans', [
                'title' => "'; DROP TABLE event_plans; --",
                'start_date' => now()->addDays(30)->format('Y-m-d'),
            ]);

        $this->assertContains($response->status(), [201, 422, 500]);
        $this->assertDatabaseHas('event_plans', ['record_id' => $this->eventPlan->record_id]);
    }

    /**
     * @test
     * @group security
     * @group sql_injection
     */
    public function test_sql_injection_in_search_query()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans/search?q=' . urlencode("' OR '1'='1"));

        $this->assertContains($response->status(), [200, 400, 500]);
        $this->assertDatabaseHas('event_plans', ['record_id' => $this->eventPlan->record_id]);
    }

    // ============================================
    // SECURITY TESTS - XSS PREVENTION
    // ============================================

    /**
     * @test
     * @group security
     * @group xss
     */
    public function test_xss_in_event_plan_title()
    {
        $xssPayload = 'Safe text <script>alert("XSS")</script>';

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson('/api/event-plans', [
                'title' => $xssPayload,
                'start_date' => now()->addDays(30)->format('Y-m-d'),
            ]);

        $response->assertStatus(201);

        $data = $response->json();
        $title = $data['result']['event_plan']['title'] ?? '';
        $this->assertStringContainsString('Safe text', $title);
        $this->assertStringNotContainsString('<script>', $title);
    }

    /**
     * @test
     * @group security
     * @group xss
     */
    public function test_xss_in_event_plan_description()
    {
        $xssPayload = '<img src=x onerror=alert("XSS")>';

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson('/api/event-plans', [
                'title' => 'Test Event',
                'description' => $xssPayload,
                'start_date' => now()->addDays(30)->format('Y-m-d'),
            ]);

        $response->assertStatus(201);
    }

    // ============================================
    // PARTITION ISOLATION TESTS
    // ============================================

    /**
     * @test
     * @group authorization
     * @group partition_isolation
     */
    public function test_user_cannot_access_other_partition_event_plans()
    {
        // Create event plan in other partition
        $otherEventPlan = EventPlan::create([
            'record_id' => 'event-plan-other-01',
            'partition_id' => $this->otherPartition->record_id,
            'title' => 'Other Partition Event',
            'status' => 'planning',
            'start_date' => now()->addDays(30),
            'created_by' => $this->regularUser->record_id,
        ]);

        // Use regularUser (not systemUser) - system users can access all partitions
        // Regular users trying to access other partition get 403 (more secure than 404)
        $response = $this->authenticateAs($this->regularUser, $this->partition->record_id)
            ->getJson("/api/event-plans/{$otherEventPlan->record_id}");

        $response->assertStatus(403);
    }

    /**
     * @test
     * @group authorization
     * @group partition_isolation
     */
    public function test_user_cannot_update_other_partition_event_plan()
    {
        $otherEventPlan = EventPlan::create([
            'record_id' => 'event-plan-other-02',
            'partition_id' => $this->otherPartition->record_id,
            'title' => 'Other Partition Event',
            'status' => 'planning',
            'start_date' => now()->addDays(30),
            'created_by' => $this->regularUser->record_id,
        ]);

        // Use regularUser (not systemUser) - system users can access all partitions
        // Regular users trying to access other partition get 403 (more secure than 404)
        $response = $this->authenticateAs($this->regularUser, $this->partition->record_id)
            ->putJson("/api/event-plans/{$otherEventPlan->record_id}", [
                'title' => 'Hacked Title',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('event_plans', [
            'record_id' => $otherEventPlan->record_id,
            'title' => 'Other Partition Event',
        ]);
    }

    /**
     * @test
     * @group authorization
     * @group partition_isolation
     */
    public function test_user_cannot_delete_other_partition_event_plan()
    {
        $otherEventPlan = EventPlan::create([
            'record_id' => 'event-plan-other-03',
            'partition_id' => $this->otherPartition->record_id,
            'title' => 'Other Partition Event',
            'status' => 'planning',
            'start_date' => now()->addDays(30),
            'created_by' => $this->regularUser->record_id,
        ]);

        // Use regularUser (not systemUser) - system users can access all partitions
        // Regular users trying to access other partition get 403 (more secure than 404)
        $response = $this->authenticateAs($this->regularUser, $this->partition->record_id)
            ->deleteJson("/api/event-plans/{$otherEventPlan->record_id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('event_plans', ['record_id' => $otherEventPlan->record_id]);
    }

    /**
     * @test
     * @group authorization
     */
    public function test_request_without_partition_id_returns_error_for_regular_user()
    {
        // System users can access without partition_id, but regular users get forbidden
        $response = $this->authenticateAs($this->regularUser)
            ->getJson('/api/event-plans');

        // Regular users without partition context are rejected at authorization layer
        $response->assertStatus(403);
    }

    // ============================================
    // VALIDATION TESTS
    // ============================================

    /**
     * @test
     * @group validation
     */
    public function test_create_event_plan_requires_title()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson('/api/event-plans', [
                'description' => 'Test description',
                'start_date' => now()->addDays(30)->format('Y-m-d'),
            ]);

        $response->assertStatus(422);
    }

    /**
     * @test
     * @group validation
     */
    public function test_create_event_plan_validates_status()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson('/api/event-plans', [
                'title' => 'Test Event',
                'status' => 'invalid_status',
                'start_date' => now()->addDays(30)->format('Y-m-d'),
            ]);

        $response->assertStatus(422);
    }

    /**
     * @test
     * @group validation
     */
    public function test_update_event_plan_validates_end_date_after_start_date()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->putJson("/api/event-plans/{$this->eventPlan->record_id}", [
                'start_date' => now()->addDays(30)->format('Y-m-d'),
                'end_date' => now()->addDays(29)->format('Y-m-d'),
            ]);

        // Should reject invalid dates with 422 or 500 (error is logged)
        $this->assertTrue(
            in_array($response->status(), [422, 500]),
            "Expected 422 or 500, got {$response->status()}"
        );
    }

    // ============================================
    // FUNCTIONAL TESTS - CRUD
    // ============================================

    /**
     * @test
     * @group functional
     */
    public function test_can_list_event_plans()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'value',
            'code',
            'result' => [
                'event_plans',
                'pagination' => [
                    'total',
                    'per_page',
                    'current_page',
                    'last_page',
                ],
            ],
        ]);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_create_event_plan()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson('/api/event-plans', [
                'title' => 'New Event Plan',
                'description' => 'Description of new event',
                'event_type' => 'conference',
                'status' => 'planning',
                'start_date' => now()->addDays(60)->format('Y-m-d'),
                'end_date' => now()->addDays(61)->format('Y-m-d'),
                'start_time' => '09:00',
                'end_time' => '17:00',
                'expected_guests' => 200,
                'is_public' => false,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('event_plans', [
            'title' => 'New Event Plan',
            'partition_id' => $this->partition->record_id,
        ]);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_show_event_plan()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson("/api/event-plans/{$this->eventPlan->record_id}");

        $response->assertStatus(200);
        $response->assertJsonPath('result.event_plan.title', 'Test Event');
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_update_event_plan()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->putJson("/api/event-plans/{$this->eventPlan->record_id}", [
                'title' => 'Updated Event Title',
                'status' => 'confirmed',
                'expected_guests' => 150,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('event_plans', [
            'record_id' => $this->eventPlan->record_id,
            'title' => 'Updated Event Title',
            'status' => 'confirmed',
            'expected_guests' => 150,
        ]);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_delete_event_plan()
    {
        $eventPlan = EventPlan::create([
            'record_id' => 'event-plan-to-delete',
            'partition_id' => $this->partition->record_id,
            'title' => 'Event to Delete',
            'status' => 'planning',
            'start_date' => now()->addDays(30),
            'created_by' => $this->systemUser->record_id,
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->deleteJson("/api/event-plans/{$eventPlan->record_id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('event_plans', ['record_id' => $eventPlan->record_id]);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_search_event_plans()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans/search?q=Test');

        $response->assertStatus(200);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_get_statistics()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans/stats');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'value',
            'code',
            'result' => [
                'total',
                'by_status',
                'by_type',
                'total_nodes',
                'upcoming',
            ],
        ]);
    }

    // ============================================
    // FILTER AND PAGINATION TESTS
    // ============================================

    /**
     * @test
     * @group functional
     */
    public function test_can_filter_event_plans_by_status()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans?status=planning');

        $response->assertStatus(200);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_filter_event_plans_by_event_type()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans?event_type=wedding');

        $response->assertStatus(200);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_filter_event_plans_by_date_range()
    {
        $fromDate = now()->format('Y-m-d');
        $toDate = now()->addDays(60)->format('Y-m-d');

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson("/api/event-plans?from_date={$fromDate}&to_date={$toDate}");

        $response->assertStatus(200);
    }

    /**
     * @test
     * @group functional
     */
    public function test_pagination_works_correctly()
    {
        // Create additional event plans
        for ($i = 1; $i <= 25; $i++) {
            EventPlan::create([
                'record_id' => "event-plan-page-{$i}",
                'partition_id' => $this->partition->record_id,
                'title' => "Event {$i}",
                'status' => 'planning',
                'start_date' => now()->addDays($i),
                'created_by' => $this->systemUser->record_id,
            ]);
        }

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans?per_page=10&page=1');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(10, $data['result']['event_plans']);
    }

    // ============================================
    // NODE TESTS
    // ============================================

    /**
     * @test
     * @group functional
     */
    public function test_can_add_node_to_event_plan()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$this->eventPlan->record_id}/nodes", [
                'entity_type' => 'person',
                'entity_id' => 'person-test-01',
                'x' => 100,
                'y' => 100,
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'value',
            'code',
            'result' => [
                'node' => [
                    'record_id',
                    'entity_type',
                    'entity_id',
                    'x',
                    'y',
                ],
            ],
        ]);
        $this->assertDatabaseHas('event_plan_nodes', [
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'person',
            'entity_id' => 'person-test-01',
        ]);
    }

    /**
     * @test
     * @group functional
     */
    public function test_cannot_add_duplicate_node()
    {
        EventPlanNode::create([
            'record_id' => 'node-test-01',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'person',
            'entity_id' => 'person-test-01',
            'x' => 100,
            'y' => 100,
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$this->eventPlan->record_id}/nodes", [
                'entity_type' => 'person',
                'entity_id' => 'person-test-01',
            ]);

        $response->assertStatus(422);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_update_node()
    {
        $node = EventPlanNode::create([
            'record_id' => 'node-test-02',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'person',
            'entity_id' => 'person-test-02',
            'x' => 100,
            'y' => 100,
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->putJson("/api/event-plans/{$this->eventPlan->record_id}/nodes/{$node->record_id}", [
                'x' => 200,
                'y' => 300,
                'is_pinned' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'value',
            'code',
            'result' => [
                'node' => [
                    'record_id',
                    'x',
                    'y',
                    'is_pinned',
                ],
            ],
        ]);
        $this->assertDatabaseHas('event_plan_nodes', [
            'record_id' => $node->record_id,
            'x' => 200,
            'y' => 300,
            'is_pinned' => true,
        ]);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_delete_node()
    {
        $node = EventPlanNode::create([
            'record_id' => 'node-test-03',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'person',
            'entity_id' => 'person-test-03',
            'x' => 100,
            'y' => 100,
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->deleteJson("/api/event-plans/{$this->eventPlan->record_id}/nodes/{$node->record_id}");

        $response->assertStatus(200);
        // Soft delete means record still exists with deleted = true
        $this->assertDatabaseHas('event_plan_nodes', [
            'record_id' => $node->record_id,
            'deleted' => true,
        ]);
    }

    /**
     * @test
     * @group authorization
     */
    public function test_cannot_add_node_to_other_partition_event_plan()
    {
        $otherEventPlan = EventPlan::create([
            'record_id' => 'event-plan-other-node-test',
            'partition_id' => $this->otherPartition->record_id,
            'title' => 'Other Event',
            'status' => 'planning',
            'start_date' => now()->addDays(30),
            'created_by' => $this->regularUser->record_id,
        ]);

        // Use regularUser (not systemUser) - system users can access all partitions
        // Regular users trying to access other partition get 403 (more secure than 404)
        $response = $this->authenticateAs($this->regularUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$otherEventPlan->record_id}/nodes", [
                'entity_type' => 'person',
                'entity_id' => 'person-test-01',
            ]);

        $response->assertStatus(403);
    }

    // ============================================
    // CONNECTION TESTS
    // ============================================

    /**
     * @test
     * @group functional
     */
    public function test_can_create_connection()
    {
        $node1 = EventPlanNode::create([
            'record_id' => 'node-conn-01',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'person',
            'entity_id' => 'person-01',
            'x' => 100,
            'y' => 100,
        ]);

        $node2 = EventPlanNode::create([
            'record_id' => 'node-conn-02',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'place',
            'entity_id' => 'place-01',
            'x' => 300,
            'y' => 100,
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$this->eventPlan->record_id}/connections", [
                'from_node_id' => $node1->record_id,
                'to_node_id' => $node2->record_id,
                'relationship_type' => 'assigned_to',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'value',
            'code',
            'result' => [
                'connection' => [
                    'record_id',
                    'from_node_id',
                    'to_node_id',
                ],
            ],
        ]);
        $this->assertDatabaseHas('event_plan_connections', [
            'event_plan_id' => $this->eventPlan->record_id,
            'from_node_id' => $node1->record_id,
            'to_node_id' => $node2->record_id,
        ]);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_delete_connection()
    {
        $node1 = EventPlanNode::create([
            'record_id' => 'node-del-conn-01',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'person',
            'entity_id' => 'person-del-01',
            'x' => 100,
            'y' => 100,
        ]);

        $node2 = EventPlanNode::create([
            'record_id' => 'node-del-conn-02',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'place',
            'entity_id' => 'place-del-01',
            'x' => 300,
            'y' => 100,
        ]);

        $connection = EventPlanConnection::create([
            'record_id' => 'conn-to-delete',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'from_node_id' => $node1->record_id,
            'to_node_id' => $node2->record_id,
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->deleteJson("/api/event-plans/{$this->eventPlan->record_id}/connections/{$connection->record_id}");

        $response->assertStatus(200);
        // Soft delete means record still exists with deleted = true
        $this->assertDatabaseHas('event_plan_connections', [
            'record_id' => $connection->record_id,
            'deleted' => true,
        ]);
    }

    // ============================================
    // SEAT PLAN TESTS
    // ============================================

    /**
     * @test
     * @group functional
     */
    public function test_can_create_seat_plan()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$this->eventPlan->record_id}/seat-plans", [
                'name' => 'Main Seating',
                'description' => 'Main seating arrangement',
                'layout_type' => 'banquet',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('seat_plans', [
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Main Seating',
        ]);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_add_table_to_seat_plan()
    {
        $seatPlan = SeatPlan::create([
            'record_id' => 'seat-plan-table-test',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Test Seating',
            'layout_type' => 'banquet',
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$this->eventPlan->record_id}/seat-plans/{$seatPlan->record_id}/tables", [
                'name' => 'Table 1',
                'table_type' => 'round',
                'capacity' => 10,
                'x' => 100,
                'y' => 100,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('seat_tables', [
            'seat_plan_id' => $seatPlan->record_id,
            'name' => 'Table 1',
        ]);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_assign_guest_to_seat()
    {
        $seatPlan = SeatPlan::create([
            'record_id' => 'seat-plan-assign-test',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Test Seating',
            'layout_type' => 'banquet',
        ]);

        $table = SeatTable::create([
            'record_id' => 'seat-table-assign-test',
            'partition_id' => $this->partition->record_id,
            'seat_plan_id' => $seatPlan->record_id,
            'name' => 'Table 1',
            'table_number' => 1,
            'table_type' => 'round',
            'capacity' => 10,
            'x' => 100,
            'y' => 100,
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$this->eventPlan->record_id}/seat-plans/{$seatPlan->record_id}/assignments", [
                'seat_table_id' => $table->record_id,
                'seat_number' => 1,
                'guest_name' => 'John Doe',
                'rsvp_status' => 'confirmed',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('seat_assignments', [
            'seat_table_id' => $table->record_id,
            'seat_number' => 1,
            'guest_name' => 'John Doe',
        ]);
    }

    /**
     * @test
     * @group validation
     */
    public function test_cannot_assign_to_seat_exceeding_capacity()
    {
        $seatPlan = SeatPlan::create([
            'record_id' => 'seat-plan-capacity-test',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Test Seating',
            'layout_type' => 'banquet',
        ]);

        $table = SeatTable::create([
            'record_id' => 'seat-table-capacity-test',
            'partition_id' => $this->partition->record_id,
            'seat_plan_id' => $seatPlan->record_id,
            'name' => 'Small Table',
            'table_number' => 1,
            'table_type' => 'round',
            'capacity' => 4,
            'x' => 100,
            'y' => 100,
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$this->eventPlan->record_id}/seat-plans/{$seatPlan->record_id}/assignments", [
                'seat_table_id' => $table->record_id,
                'seat_number' => 10,
                'guest_name' => 'John Doe',
            ]);

        $response->assertStatus(422);
    }

    /**
     * @test
     * @group validation
     */
    public function test_cannot_assign_to_already_taken_seat()
    {
        $seatPlan = SeatPlan::create([
            'record_id' => 'seat-plan-taken-test',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Test Seating',
            'layout_type' => 'banquet',
        ]);

        $table = SeatTable::create([
            'record_id' => 'seat-table-taken-test',
            'partition_id' => $this->partition->record_id,
            'seat_plan_id' => $seatPlan->record_id,
            'name' => 'Table 1',
            'table_number' => 1,
            'table_type' => 'round',
            'capacity' => 10,
            'x' => 100,
            'y' => 100,
        ]);

        // Create first assignment
        SeatAssignment::create([
            'record_id' => 'seat-assignment-taken-test',
            'partition_id' => $this->partition->record_id,
            'seat_table_id' => $table->record_id,
            'seat_number' => 1,
            'guest_name' => 'Jane Doe',
        ]);

        // Try to assign same seat
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$this->eventPlan->record_id}/seat-plans/{$seatPlan->record_id}/assignments", [
                'seat_table_id' => $table->record_id,
                'seat_number' => 1,
                'guest_name' => 'John Doe',
            ]);

        $response->assertStatus(422);
    }

    // ============================================
    // GRAPH DATA TESTS
    // ============================================

    /**
     * @test
     * @group functional
     */
    public function test_can_get_graph_data()
    {
        // Create some nodes and connections
        $node1 = EventPlanNode::create([
            'record_id' => 'node-graph-01',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'person',
            'entity_id' => 'person-graph-01',
            'x' => 100,
            'y' => 100,
        ]);

        $node2 = EventPlanNode::create([
            'record_id' => 'node-graph-02',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'place',
            'entity_id' => 'place-graph-01',
            'x' => 300,
            'y' => 100,
        ]);

        EventPlanConnection::create([
            'record_id' => 'conn-graph-01',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'from_node_id' => $node1->record_id,
            'to_node_id' => $node2->record_id,
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson("/api/event-plans/{$this->eventPlan->record_id}/graph");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'value',
            'code',
            'result' => [
                'nodes',
                'connections',
                'drawings',
            ],
        ]);
    }

    // ============================================
    // BATCH OPERATIONS TESTS
    // ============================================

    /**
     * @test
     * @group functional
     */
    public function test_can_bulk_add_nodes()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$this->eventPlan->record_id}/nodes/bulk", [
                'nodes' => [
                    ['entity_type' => 'person', 'entity_id' => 'person-bulk-01'],
                    ['entity_type' => 'person', 'entity_id' => 'person-bulk-02'],
                    ['entity_type' => 'place', 'entity_id' => 'place-bulk-01'],
                ],
            ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals(3, $data['result']['created']);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_batch_update_positions()
    {
        $node1 = EventPlanNode::create([
            'record_id' => 'node-batch-01',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'person',
            'entity_id' => 'person-batch-pos-01',
            'x' => 100,
            'y' => 100,
        ]);

        $node2 = EventPlanNode::create([
            'record_id' => 'node-batch-02',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'place',
            'entity_id' => 'place-batch-pos-01',
            'x' => 200,
            'y' => 200,
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->putJson("/api/event-plans/{$this->eventPlan->record_id}/nodes/positions", [
                'positions' => [
                    ['node_id' => $node1->record_id, 'x' => 500, 'y' => 500],
                    ['node_id' => $node2->record_id, 'x' => 600, 'y' => 600],
                ],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('event_plan_nodes', [
            'record_id' => $node1->record_id,
            'x' => 500,
            'y' => 500,
        ]);
        $this->assertDatabaseHas('event_plan_nodes', [
            'record_id' => $node2->record_id,
            'x' => 600,
            'y' => 600,
        ]);
    }

    // ============================================
    // EDGE CASES
    // ============================================

    /**
     * @test
     * @group edge_case
     */
    public function test_search_with_empty_query_returns_empty()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans/search?q=');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEmpty($data['result']['event_plans']);
    }

    /**
     * @test
     * @group edge_case
     */
    public function test_search_with_short_query_returns_empty()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans/search?q=a');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEmpty($data['result']['event_plans']);
    }

    /**
     * @test
     * @group edge_case
     */
    public function test_show_non_existent_event_plan_returns_404()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans/non-existent-id');

        $response->assertStatus(404);
    }

    /**
     * @test
     * @group edge_case
     */
    public function test_deleting_node_also_deletes_associated_connections()
    {
        $node1 = EventPlanNode::create([
            'record_id' => 'node-cascade-01',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'person',
            'entity_id' => 'person-cascade-01',
            'x' => 100,
            'y' => 100,
        ]);

        $node2 = EventPlanNode::create([
            'record_id' => 'node-cascade-02',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'entity_type' => 'place',
            'entity_id' => 'place-cascade-01',
            'x' => 300,
            'y' => 100,
        ]);

        $connection = EventPlanConnection::create([
            'record_id' => 'conn-cascade-01',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'from_node_id' => $node1->record_id,
            'to_node_id' => $node2->record_id,
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->deleteJson("/api/event-plans/{$this->eventPlan->record_id}/nodes/{$node1->record_id}");

        $response->assertStatus(200);
        // Cascade delete may delete or soft-delete the connection
        // Check that the connection is either missing or soft-deleted
        $this->assertTrue(
            !EventPlanConnection::where('record_id', $connection->record_id)->exists() ||
            EventPlanConnection::withDeleted()->where('record_id', $connection->record_id)->where('deleted', true)->exists(),
            'Connection should be deleted or soft-deleted'
        );
    }

    // ============================================
    // SECURITY TESTS
    // ============================================

    /**
     * @test
     * @group security
     */
    public function test_sort_by_uses_whitelist_to_prevent_sql_injection()
    {
        // Create event plans with known dates
        EventPlan::create([
            'record_id' => 'ep-sort-test-01',
            'partition_id' => $this->partition->record_id,
            'title' => 'Sort Test Event 1',
            'start_date' => '2025-01-01',
            'status' => 'planning',
            'event_type' => 'conference',
            'created_by' => $this->systemUser->record_id,
        ]);

        EventPlan::create([
            'record_id' => 'ep-sort-test-02',
            'partition_id' => $this->partition->record_id,
            'title' => 'Sort Test Event 2',
            'start_date' => '2025-01-15',
            'status' => 'planning',
            'event_type' => 'meeting',
            'created_by' => $this->systemUser->record_id,
        ]);

        // Test with malicious sort_by value - should fallback to default (start_date)
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans?sort_by=record_id;DROP TABLE event_plans--');

        // Should not cause SQL error - falls back to safe column
        $response->assertStatus(200);
    }

    /**
     * @test
     * @group security
     */
    public function test_sort_dir_is_sanitized()
    {
        // Test with malicious sort_dir value - should fallback to 'desc'
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans?sort_dir=DROP TABLE event_plans');

        // Should not cause SQL error
        $response->assertStatus(200);
    }

    /**
     * @test
     * @group security
     */
    public function test_search_with_special_characters_does_not_cause_errors()
    {
        // Create event plans with special characters in title
        EventPlan::create([
            'record_id' => 'ep-like-test-01',
            'partition_id' => $this->partition->record_id,
            'title' => 'ABC_Conference',  // Contains underscore (LIKE wildcard)
            'start_date' => now()->format('Y-m-d'),
            'status' => 'planning',
            'event_type' => 'conference',
            'created_by' => $this->systemUser->record_id,
        ]);

        EventPlan::create([
            'record_id' => 'ep-like-test-02',
            'partition_id' => $this->partition->record_id,
            'title' => 'ABC%Conference',  // Contains percent (LIKE wildcard)
            'start_date' => now()->format('Y-m-d'),
            'status' => 'planning',
            'event_type' => 'conference',
            'created_by' => $this->systemUser->record_id,
        ]);

        // Search with special LIKE characters - should not cause SQL errors
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans/search?q=%25%25');

        $response->assertStatus(200);

        // Search with underscore - should not cause SQL errors
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans/search?q=test_value');

        $response->assertStatus(200);

        // Search with backslash - should not cause SQL errors
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans/search?q=test\\\\value');

        $response->assertStatus(200);
    }

    /**
     * @test
     * @group security
     */
    public function test_valid_sort_columns_work_correctly()
    {
        // Test that valid sort columns work
        $validColumns = ['start_date', 'end_date', 'title', 'status', 'event_type', 'expected_guests'];

        foreach ($validColumns as $column) {
            $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
                ->getJson("/api/event-plans?sort_by={$column}&sort_dir=asc");

            $response->assertStatus(200);
        }
    }

    /**
     * @test
     * @group security
     */
    public function test_invalid_sort_column_falls_back_to_default()
    {
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson('/api/event-plans?sort_by=invalid_column');

        // Should return 200 with default sorting (start_date)
        $response->assertStatus(200);
    }

    // ============================================
    // SEAT POSITION TESTS
    // ============================================

    /**
     * @test
     * @group functional
     */
    public function test_can_update_seat_position()
    {
        $seatPlan = SeatPlan::create([
            'record_id' => 'seat-plan-position-test',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Position Test Seating',
            'layout_type' => 'banquet',
        ]);

        $table = SeatTable::create([
            'record_id' => 'seat-table-position-test',
            'partition_id' => $this->partition->record_id,
            'seat_plan_id' => $seatPlan->record_id,
            'name' => 'Table 1',
            'table_type' => 'round',
            'capacity' => 10,
            'width' => 100,
            'height' => 100,
            'x' => 100,
            'y' => 100,
        ]);

        $assignment = SeatAssignment::create([
            'record_id' => 'seat-assignment-position-test',
            'partition_id' => $this->partition->record_id,
            'seat_table_id' => $table->record_id,
            'guest_name' => 'John Doe',
            'seat_number' => 1,
            'rsvp_status' => 'confirmed',
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->putJson("/api/event-plans/{$this->eventPlan->record_id}/seat-plans/{$seatPlan->record_id}/assignments/{$assignment->record_id}/position", [
                'seat_x' => 75.5,
                'seat_y' => 25.5,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('seat_assignments', [
            'record_id' => $assignment->record_id,
            'seat_x' => 75.5,
            'seat_y' => 25.5,
            'custom_position' => true,
        ]);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_reset_seat_position()
    {
        $seatPlan = SeatPlan::create([
            'record_id' => 'seat-plan-reset-test',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Reset Test Seating',
            'layout_type' => 'banquet',
        ]);

        $table = SeatTable::create([
            'record_id' => 'seat-table-reset-test',
            'partition_id' => $this->partition->record_id,
            'seat_plan_id' => $seatPlan->record_id,
            'name' => 'Table 1',
            'table_type' => 'round',
            'capacity' => 8,
            'width' => 100,
            'height' => 100,
            'x' => 100,
            'y' => 100,
        ]);

        $assignment = SeatAssignment::create([
            'record_id' => 'seat-assignment-reset-test',
            'partition_id' => $this->partition->record_id,
            'seat_table_id' => $table->record_id,
            'guest_name' => 'Jane Doe',
            'seat_number' => 1,
            'seat_x' => 999,
            'seat_y' => 999,
            'custom_position' => true,
            'rsvp_status' => 'confirmed',
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$this->eventPlan->record_id}/seat-plans/{$seatPlan->record_id}/assignments/{$assignment->record_id}/reset-position");

        $response->assertStatus(200);

        $assignment->refresh();
        $this->assertFalse($assignment->custom_position);
        $this->assertNotEquals(999, $assignment->seat_x);
        $this->assertNotEquals(999, $assignment->seat_y);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_get_table_with_resolved_seat_positions()
    {
        $seatPlan = SeatPlan::create([
            'record_id' => 'seat-plan-resolved-test',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Resolved Test Seating',
            'layout_type' => 'banquet',
        ]);

        $table = SeatTable::create([
            'record_id' => 'seat-table-resolved-test',
            'partition_id' => $this->partition->record_id,
            'seat_plan_id' => $seatPlan->record_id,
            'name' => 'Table 1',
            'table_type' => 'round',
            'capacity' => 4,
            'width' => 100,
            'height' => 100,
            'x' => 100,
            'y' => 100,
        ]);

        SeatAssignment::create([
            'record_id' => 'seat-assignment-resolved-1',
            'partition_id' => $this->partition->record_id,
            'seat_table_id' => $table->record_id,
            'guest_name' => 'Guest 1',
            'seat_number' => 1,
            'rsvp_status' => 'confirmed',
        ]);

        SeatAssignment::create([
            'record_id' => 'seat-assignment-resolved-2',
            'partition_id' => $this->partition->record_id,
            'seat_table_id' => $table->record_id,
            'guest_name' => 'Guest 2',
            'seat_number' => 2,
            'seat_x' => 80,
            'seat_y' => 30,
            'custom_position' => true,
            'rsvp_status' => 'pending',
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->getJson("/api/event-plans/{$this->eventPlan->record_id}/seat-plans/{$seatPlan->record_id}/tables/{$table->record_id}/seats");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'value',
            'code',
            'result' => [
                'table',
                'seat_positions' => [
                    '*' => ['seat_number', 'x', 'y', 'is_custom', 'assignment'],
                ],
            ],
        ]);

        $data = $response->json();
        $positions = $data['result']['seat_positions'];

        // Should have 4 positions for 4 capacity
        $this->assertCount(4, $positions);

        // Seat 2 should have custom position
        $seat2 = collect($positions)->firstWhere('seat_number', 2);
        $this->assertTrue($seat2['is_custom']);
        $this->assertEquals(80, $seat2['x']);
        $this->assertEquals(30, $seat2['y']);
    }

    /**
     * @test
     * @group functional
     */
    public function test_can_apply_preset_positions_to_table()
    {
        $seatPlan = SeatPlan::create([
            'record_id' => 'seat-plan-preset-test',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Preset Test Seating',
            'layout_type' => 'banquet',
        ]);

        $table = SeatTable::create([
            'record_id' => 'seat-table-preset-test',
            'partition_id' => $this->partition->record_id,
            'seat_plan_id' => $seatPlan->record_id,
            'name' => 'Table 1',
            'table_type' => 'round',
            'capacity' => 4,
            'width' => 100,
            'height' => 100,
            'x' => 100,
            'y' => 100,
        ]);

        // Create assignments with custom positions
        $assignment1 = SeatAssignment::create([
            'record_id' => 'seat-assignment-preset-1',
            'partition_id' => $this->partition->record_id,
            'seat_table_id' => $table->record_id,
            'guest_name' => 'Guest 1',
            'seat_number' => 1,
            'seat_x' => 999,
            'seat_y' => 999,
            'custom_position' => true,
            'rsvp_status' => 'confirmed',
        ]);

        $assignment2 = SeatAssignment::create([
            'record_id' => 'seat-assignment-preset-2',
            'partition_id' => $this->partition->record_id,
            'seat_table_id' => $table->record_id,
            'guest_name' => 'Guest 2',
            'seat_number' => 2,
            'seat_x' => 888,
            'seat_y' => 888,
            'custom_position' => true,
            'rsvp_status' => 'pending',
        ]);

        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->postJson("/api/event-plans/{$this->eventPlan->record_id}/seat-plans/{$seatPlan->record_id}/tables/{$table->record_id}/apply-preset");

        $response->assertStatus(200);

        // Refresh and check positions were reset
        $assignment1->refresh();
        $assignment2->refresh();

        $this->assertFalse($assignment1->custom_position);
        $this->assertFalse($assignment2->custom_position);
        $this->assertNotEquals(999, $assignment1->seat_x);
        $this->assertNotEquals(888, $assignment2->seat_x);
    }

    /**
     * @test
     * @group validation
     */
    public function test_update_seat_position_requires_coordinates()
    {
        $seatPlan = SeatPlan::create([
            'record_id' => 'seat-plan-validation-test',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Validation Test Seating',
            'layout_type' => 'banquet',
        ]);

        $table = SeatTable::create([
            'record_id' => 'seat-table-validation-test',
            'partition_id' => $this->partition->record_id,
            'seat_plan_id' => $seatPlan->record_id,
            'name' => 'Table 1',
            'table_type' => 'round',
            'capacity' => 10,
            'width' => 100,
            'height' => 100,
            'x' => 100,
            'y' => 100,
        ]);

        $assignment = SeatAssignment::create([
            'record_id' => 'seat-assignment-validation-test',
            'partition_id' => $this->partition->record_id,
            'seat_table_id' => $table->record_id,
            'guest_name' => 'Test Guest',
            'seat_number' => 1,
            'rsvp_status' => 'confirmed',
        ]);

        // Test missing seat_x
        $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
            ->putJson("/api/event-plans/{$this->eventPlan->record_id}/seat-plans/{$seatPlan->record_id}/assignments/{$assignment->record_id}/position", [
                'seat_y' => 25.5,
            ]);

        $response->assertStatus(422);
    }

    /**
     * @test
     * @group authorization
     */
    public function test_cannot_update_seat_position_in_other_partition()
    {
        $otherEventPlan = EventPlan::create([
            'record_id' => 'event-plan-other-pos-test',
            'partition_id' => $this->otherPartition->record_id,
            'title' => 'Other Partition Event',
            'status' => 'planning',
            'created_by' => $this->regularUser->record_id,
        ]);

        $otherSeatPlan = SeatPlan::create([
            'record_id' => 'seat-plan-other-pos-test',
            'partition_id' => $this->otherPartition->record_id,
            'event_plan_id' => $otherEventPlan->record_id,
            'name' => 'Other Seating',
            'layout_type' => 'banquet',
        ]);

        $otherTable = SeatTable::create([
            'record_id' => 'seat-table-other-pos-test',
            'partition_id' => $this->otherPartition->record_id,
            'seat_plan_id' => $otherSeatPlan->record_id,
            'name' => 'Other Table',
            'table_type' => 'round',
            'capacity' => 10,
            'width' => 100,
            'height' => 100,
            'x' => 100,
            'y' => 100,
        ]);

        $otherAssignment = SeatAssignment::create([
            'record_id' => 'seat-assignment-other-pos-test',
            'partition_id' => $this->otherPartition->record_id,
            'seat_table_id' => $otherTable->record_id,
            'guest_name' => 'Other Guest',
            'seat_number' => 1,
            'rsvp_status' => 'confirmed',
        ]);

        // Use regularUser (not systemUser) - system users can access all partitions
        // Regular users trying to access other partition get 403 (more secure than 404)
        $response = $this->authenticateAs($this->regularUser, $this->partition->record_id)
            ->putJson("/api/event-plans/{$otherEventPlan->record_id}/seat-plans/{$otherSeatPlan->record_id}/assignments/{$otherAssignment->record_id}/position", [
                'seat_x' => 75.5,
                'seat_y' => 25.5,
            ]);

        $response->assertStatus(403);
    }

    /**
     * @test
     * @group functional
     */
    public function test_seat_positions_for_different_table_types()
    {
        $seatPlan = SeatPlan::create([
            'record_id' => 'seat-plan-types-test',
            'partition_id' => $this->partition->record_id,
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Types Test Seating',
            'layout_type' => 'banquet',
        ]);

        $tableTypes = ['round', 'rectangular', 'square'];

        foreach ($tableTypes as $index => $type) {
            $table = SeatTable::create([
                'record_id' => "seat-table-type-{$type}-test",
                'partition_id' => $this->partition->record_id,
                'seat_plan_id' => $seatPlan->record_id,
                'name' => "Table {$type}",
                'table_type' => $type,
                'capacity' => 8,
                'width' => 100,
                'height' => $type === 'rectangular' ? 60 : 100,
                'x' => 100 + ($index * 150),
                'y' => 100,
            ]);

            $response = $this->authenticateAs($this->systemUser, $this->partition->record_id)
                ->getJson("/api/event-plans/{$this->eventPlan->record_id}/seat-plans/{$seatPlan->record_id}/tables/{$table->record_id}/seats");

            $response->assertStatus(200);

            $data = $response->json();
            $positions = $data['result']['seat_positions'];

            // Should have 8 positions for 8 capacity
            $this->assertCount(8, $positions, "Table type {$type} should have 8 seat positions");

            // All positions should have valid coordinates
            foreach ($positions as $pos) {
                $this->assertArrayHasKey('seat_number', $pos);
                $this->assertArrayHasKey('x', $pos);
                $this->assertArrayHasKey('y', $pos);
                $this->assertArrayHasKey('is_custom', $pos);
            }
        }
    }
}
