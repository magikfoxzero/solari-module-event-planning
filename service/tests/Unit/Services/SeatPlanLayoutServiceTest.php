<?php

namespace Tests\Unit\Services;

use NewSolari\Core\Identity\Models\IdentityPartition;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\EventPlanning\Models\EventPlan;
use NewSolari\EventPlanning\Models\SeatPlan;
use NewSolari\EventPlanning\Models\SeatTable;
use NewSolari\EventPlanning\Models\SeatAssignment;
use NewSolari\EventPlanning\Services\SeatPlanLayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SeatPlanLayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SeatPlanLayoutService $service;
    protected string $partitionId;
    protected IdentityUser $user;
    protected EventPlan $eventPlan;
    protected SeatPlan $seatPlan;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Create test partition
        $partition = IdentityPartition::create([
            'record_id' => 'partition-test-seat-layout-0001',
            'name' => 'Test Partition',
            'description' => 'Test partition for seat layout tests',
        ]);
        $this->partitionId = $partition->record_id;

        // Create test user
        $this->user = IdentityUser::create([
            'record_id' => 'user-test-seat-layout-0001',
            'partition_id' => $this->partitionId,
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password_hash' => 'password',
            'is_active' => true,
        ]);
        $this->user->setSystemUser(true);

        // Create test event plan
        $this->eventPlan = EventPlan::create([
            'record_id' => 'ep-test-seat-layout-0001',
            'partition_id' => $this->partitionId,
            'title' => 'Test Event',
            'status' => 'planning',
            'created_by' => $this->user->record_id,
        ]);

        // Create test seat plan
        $this->seatPlan = SeatPlan::create([
            'record_id' => 'sp-test-seat-layout-0001',
            'partition_id' => $this->partitionId,
            'event_plan_id' => $this->eventPlan->record_id,
            'name' => 'Test Seat Plan',
            'layout_type' => 'banquet',
        ]);

        $this->service = app(SeatPlanLayoutService::class);
    }

    // ===== Round Table Tests =====

    public function test_calculate_round_table_positions_with_8_seats(): void
    {
        $table = $this->createTable('round', 8, 100, 100);
        $positions = $this->service->calculatePresetSeatPositions($table);

        $this->assertCount(8, $positions);

        // First seat should be at top (angle = -90 degrees)
        $this->assertEquals(1, $positions[0]['seat_number']);
        $this->assertEquals(50, $positions[0]['x']); // centerX
        $this->assertLessThan(0, $positions[0]['y']); // Above center (negative y relative to center)

        // All seats should have unique positions
        $uniquePositions = collect($positions)->map(fn($p) => $p['x'] . ',' . $p['y'])->unique();
        $this->assertCount(8, $uniquePositions);
    }

    public function test_calculate_round_table_positions_evenly_distributed(): void
    {
        $table = $this->createTable('round', 4, 100, 100);
        $positions = $this->service->calculatePresetSeatPositions($table);

        $this->assertCount(4, $positions);

        // For 4 seats, they should be at top, right, bottom, left
        // Seat 1 (top), Seat 2 (right), Seat 3 (bottom), Seat 4 (left)
        $centerX = 50;
        $centerY = 50;
        $radius = 70; // 50 + 20 offset

        // Check seat 1 is at top (x=center, y=top)
        $this->assertEqualsWithDelta($centerX, $positions[0]['x'], 1);
        $this->assertLessThan($centerY, $positions[0]['y']);

        // Check seat 3 is at bottom (x=center, y=bottom)
        $this->assertEqualsWithDelta($centerX, $positions[2]['x'], 1);
        $this->assertGreaterThan($centerY, $positions[2]['y']);
    }

    // ===== Rectangular Table Tests =====

    public function test_calculate_rectangular_table_positions(): void
    {
        $table = $this->createTable('rectangular', 8, 200, 80);
        $positions = $this->service->calculatePresetSeatPositions($table);

        $this->assertCount(8, $positions);

        // First 4 seats should be on top (negative y)
        for ($i = 0; $i < 4; $i++) {
            $this->assertEquals(-20, $positions[$i]['y'], "Seat " . ($i + 1) . " should be on top");
        }

        // Last 4 seats should be on bottom (y = height + offset)
        for ($i = 4; $i < 8; $i++) {
            $this->assertEquals(100, $positions[$i]['y'], "Seat " . ($i + 1) . " should be on bottom");
        }
    }

    public function test_calculate_rectangular_table_positions_even_spacing(): void
    {
        $table = $this->createTable('rectangular', 6, 200, 80);
        $positions = $this->service->calculatePresetSeatPositions($table);

        $this->assertCount(6, $positions);

        // 3 seats per side, spacing = 200 / (3+1) = 50
        // Top side: x = 50, 100, 150
        $this->assertEqualsWithDelta(50, $positions[0]['x'], 1);
        $this->assertEqualsWithDelta(100, $positions[1]['x'], 1);
        $this->assertEqualsWithDelta(150, $positions[2]['x'], 1);
    }

    // ===== Square Table Tests =====

    public function test_calculate_square_table_positions(): void
    {
        $table = $this->createTable('square', 8, 100, 100);
        $positions = $this->service->calculatePresetSeatPositions($table);

        $this->assertCount(8, $positions);

        // 2 seats per side for 8 seats
        // Top (y = -20), Right (x = 120), Bottom (y = 120), Left (x = -20)

        // First 2 on top
        $this->assertEquals(-20, $positions[0]['y']);
        $this->assertEquals(-20, $positions[1]['y']);

        // Next 2 on right
        $this->assertEquals(120, $positions[2]['x']);
        $this->assertEquals(120, $positions[3]['x']);

        // Next 2 on bottom
        $this->assertEquals(120, $positions[4]['y']);
        $this->assertEquals(120, $positions[5]['y']);

        // Last 2 on left
        $this->assertEquals(-20, $positions[6]['x']);
        $this->assertEquals(-20, $positions[7]['x']);
    }

    public function test_calculate_square_table_handles_uneven_distribution(): void
    {
        // 5 seats on a square table - first side gets 2, then remaining sides get less
        $table = $this->createTable('square', 5, 100, 100);
        $positions = $this->service->calculatePresetSeatPositions($table);

        $this->assertCount(5, $positions);

        // All positions should have valid coordinates
        foreach ($positions as $pos) {
            $this->assertArrayHasKey('seat_number', $pos);
            $this->assertArrayHasKey('x', $pos);
            $this->assertArrayHasKey('y', $pos);
        }
    }

    // ===== Resolved Positions Tests =====

    public function test_get_resolved_seat_positions_with_no_assignments(): void
    {
        $table = $this->createTable('round', 4, 100, 100);
        $positions = $this->service->getResolvedSeatPositions($table);

        $this->assertCount(4, $positions);

        foreach ($positions as $pos) {
            $this->assertFalse($pos['is_custom']);
            $this->assertNull($pos['assignment']);
        }
    }

    public function test_get_resolved_seat_positions_with_assignment(): void
    {
        $table = $this->createTable('round', 4, 100, 100);

        $assignment = SeatAssignment::create([
            'record_id' => 'sa-test-resolved-0001',
            'partition_id' => $this->partitionId,
            'seat_table_id' => $table->record_id,
            'guest_name' => 'John Doe',
            'seat_number' => 1,
            'rsvp_status' => 'confirmed',
        ]);

        $table->load('assignments');
        $positions = $this->service->getResolvedSeatPositions($table);

        $this->assertCount(4, $positions);
        $this->assertNotNull($positions[0]['assignment']);
        $this->assertEquals('John Doe', $positions[0]['assignment']->guest_name);
        $this->assertFalse($positions[0]['is_custom']);
    }

    public function test_get_resolved_seat_positions_with_custom_position(): void
    {
        $table = $this->createTable('round', 4, 100, 100);

        $assignment = SeatAssignment::create([
            'record_id' => 'sa-test-custom-0001',
            'partition_id' => $this->partitionId,
            'seat_table_id' => $table->record_id,
            'guest_name' => 'Jane Doe',
            'seat_number' => 1,
            'seat_x' => 75.5,
            'seat_y' => 25.5,
            'custom_position' => true,
            'rsvp_status' => 'pending',
        ]);

        $table->load('assignments');
        $positions = $this->service->getResolvedSeatPositions($table);

        $this->assertCount(4, $positions);
        $this->assertTrue($positions[0]['is_custom']);
        $this->assertEquals(75.5, $positions[0]['x']);
        $this->assertEquals(25.5, $positions[0]['y']);
    }

    // ===== Apply Preset Tests =====

    public function test_apply_preset_positions_resets_custom_positions(): void
    {
        $table = $this->createTable('round', 4, 100, 100);

        $assignment = SeatAssignment::create([
            'record_id' => 'sa-test-reset-0001',
            'partition_id' => $this->partitionId,
            'seat_table_id' => $table->record_id,
            'guest_name' => 'Reset Test',
            'seat_number' => 1,
            'seat_x' => 999,
            'seat_y' => 999,
            'custom_position' => true,
            'rsvp_status' => 'confirmed',
        ]);

        $table->load('assignments');
        $this->service->applyPresetPositions($table);

        $assignment->refresh();

        $this->assertFalse($assignment->custom_position);
        $this->assertNotEquals(999, $assignment->seat_x);
        $this->assertNotEquals(999, $assignment->seat_y);
    }

    public function test_apply_preset_positions_sets_correct_coordinates(): void
    {
        $table = $this->createTable('round', 4, 100, 100);

        $assignment = SeatAssignment::create([
            'record_id' => 'sa-test-preset-coord-0001',
            'partition_id' => $this->partitionId,
            'seat_table_id' => $table->record_id,
            'guest_name' => 'Preset Coord Test',
            'seat_number' => 1, // Top position
            'rsvp_status' => 'confirmed',
        ]);

        $table->load('assignments');
        $this->service->applyPresetPositions($table);

        $assignment->refresh();

        // Seat 1 should be at top (x = center, y = negative)
        $this->assertEquals(50, $assignment->seat_x); // centerX
        $this->assertLessThan(50, $assignment->seat_y); // Above center
    }

    // ===== Edge Cases =====

    public function test_default_to_round_for_unknown_table_type(): void
    {
        $table = $this->createTable('unknown', 4, 100, 100);
        $positions = $this->service->calculatePresetSeatPositions($table);

        // Should default to round table calculation
        $this->assertCount(4, $positions);

        // Verify it behaves like round table (seats in a circle)
        $centerX = 50;
        $this->assertEqualsWithDelta($centerX, $positions[0]['x'], 1);
    }

    public function test_single_seat_table(): void
    {
        $table = $this->createTable('round', 1, 100, 100);
        $positions = $this->service->calculatePresetSeatPositions($table);

        $this->assertCount(1, $positions);
        $this->assertEquals(1, $positions[0]['seat_number']);
    }

    public function test_large_capacity_table(): void
    {
        $table = $this->createTable('round', 20, 200, 200);
        $positions = $this->service->calculatePresetSeatPositions($table);

        $this->assertCount(20, $positions);

        // All seat numbers should be sequential
        for ($i = 0; $i < 20; $i++) {
            $this->assertEquals($i + 1, $positions[$i]['seat_number']);
        }

        // All positions should be unique
        $uniquePositions = collect($positions)->map(fn($p) => round($p['x'], 2) . ',' . round($p['y'], 2))->unique();
        $this->assertCount(20, $uniquePositions);
    }

    // ===== Helper Methods =====

    protected function createTable(string $type, int $capacity, float $width, float $height): SeatTable
    {
        static $tableCounter = 0;
        $tableCounter++;

        return SeatTable::create([
            'record_id' => 'st-test-layout-' . str_pad($tableCounter, 4, '0', STR_PAD_LEFT),
            'partition_id' => $this->partitionId,
            'seat_plan_id' => $this->seatPlan->record_id,
            'name' => 'Test Table ' . $tableCounter,
            'table_type' => $type,
            'capacity' => $capacity,
            'width' => $width,
            'height' => $height,
            'x' => 0,
            'y' => 0,
            'rotation' => 0,
            'color' => '#8B4513',
        ]);
    }
}
