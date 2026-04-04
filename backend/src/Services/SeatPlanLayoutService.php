<?php

namespace NewSolari\EventPlanning\Services;

use NewSolari\EventPlanning\Models\SeatPlan;
use NewSolari\EventPlanning\Models\SeatTable;

class SeatPlanLayoutService
{
    /**
     * Auto-arrange tables based on layout type.
     */
    public function autoArrangeTables(SeatPlan $seatPlan): void
    {
        $tables = $seatPlan->tables()->get();

        if ($tables->isEmpty()) {
            return;
        }

        $positions = match ($seatPlan->layout_type) {
            'banquet' => $this->generateBanquetLayout($tables->count()),
            'theater' => $this->generateTheaterLayout($tables->count()),
            'classroom' => $this->generateClassroomLayout($tables->count()),
            'conference' => $this->generateConferenceLayout($tables->count()),
            default => $this->generateGridLayout($tables->count()),
        };

        foreach ($tables as $index => $table) {
            if (isset($positions[$index])) {
                $table->update([
                    'x' => $positions[$index]['x'],
                    'y' => $positions[$index]['y'],
                ]);
            }
        }
    }

    /**
     * Generate positions for a banquet layout (scattered round tables).
     */
    public function generateBanquetLayout(int $count): array
    {
        $positions = [];
        $cols = ceil(sqrt($count));
        $rows = ceil($count / $cols);
        $spacing = 200;
        $startX = 100;
        $startY = 100;

        for ($i = 0; $i < $count; $i++) {
            $col = $i % $cols;
            $row = floor($i / $cols);

            // Add some offset to even rows for visual interest
            $xOffset = ($row % 2 === 0) ? 0 : $spacing / 2;

            $positions[] = [
                'x' => $startX + ($col * $spacing) + $xOffset,
                'y' => $startY + ($row * $spacing),
            ];
        }

        return $positions;
    }

    /**
     * Generate positions for a theater layout (rows facing forward).
     */
    public function generateTheaterLayout(int $count): array
    {
        $positions = [];
        $cols = min($count, 5);
        $rows = ceil($count / $cols);
        $spacing = 180;
        $startX = 150;
        $startY = 200;

        for ($i = 0; $i < $count; $i++) {
            $col = $i % $cols;
            $row = floor($i / $cols);

            // Center each row
            $rowCount = min($cols, $count - ($row * $cols));
            $xOffset = (($cols - $rowCount) * $spacing) / 2;

            $positions[] = [
                'x' => $startX + ($col * $spacing) + $xOffset,
                'y' => $startY + ($row * $spacing),
            ];
        }

        return $positions;
    }

    /**
     * Generate positions for a classroom layout (tables in rows).
     */
    public function generateClassroomLayout(int $count): array
    {
        $positions = [];
        $cols = 3;
        $rows = ceil($count / $cols);
        $xSpacing = 250;
        $ySpacing = 150;
        $startX = 100;
        $startY = 150;

        for ($i = 0; $i < $count; $i++) {
            $col = $i % $cols;
            $row = floor($i / $cols);

            $positions[] = [
                'x' => $startX + ($col * $xSpacing),
                'y' => $startY + ($row * $ySpacing),
            ];
        }

        return $positions;
    }

    /**
     * Generate positions for a conference layout (tables around central space).
     */
    public function generateConferenceLayout(int $count): array
    {
        $positions = [];

        if ($count <= 1) {
            return [['x' => 400, 'y' => 300]];
        }

        // Arrange in a rectangle formation
        $centerX = 400;
        $centerY = 300;
        $radiusX = 300;
        $radiusY = 200;

        for ($i = 0; $i < $count; $i++) {
            $angle = (2 * M_PI * $i) / $count - M_PI / 2;
            $positions[] = [
                'x' => $centerX + ($radiusX * cos($angle)),
                'y' => $centerY + ($radiusY * sin($angle)),
            ];
        }

        return $positions;
    }

    /**
     * Generate positions for a simple grid layout.
     */
    public function generateGridLayout(int $count): array
    {
        $positions = [];
        $cols = ceil(sqrt($count));
        $spacing = 180;
        $startX = 100;
        $startY = 100;

        for ($i = 0; $i < $count; $i++) {
            $col = $i % $cols;
            $row = floor($i / $cols);

            $positions[] = [
                'x' => $startX + ($col * $spacing),
                'y' => $startY + ($row * $spacing),
            ];
        }

        return $positions;
    }

    /**
     * Auto-assign guests to tables based on capacity.
     */
    public function autoAssignGuests(
        SeatPlan $seatPlan,
        array $guestIds,
        string $partitionId,
        bool $byPerson = true
    ): array {
        $assigned = [];
        $tables = $seatPlan->tables()
            ->with('assignments')
            ->get()
            ->sortBy('table_number');

        $guestIndex = 0;

        foreach ($tables as $table) {
            while ($guestIndex < count($guestIds) && !$table->is_full) {
                $seatNumber = $table->getNextAvailableSeatNumber();

                if ($seatNumber === null) {
                    break;
                }

                $assignmentData = [
                    'partition_id' => $partitionId,
                    'seat_table_id' => $table->record_id,
                    'seat_number' => $seatNumber,
                    'rsvp_status' => 'pending',
                ];

                if ($byPerson) {
                    $assignmentData['person_id'] = $guestIds[$guestIndex];
                } else {
                    $assignmentData['guest_name'] = $guestIds[$guestIndex];
                }

                $assignment = $table->assignments()->create($assignmentData);
                $assigned[] = $assignment;
                $guestIndex++;

                // Refresh the table to get updated counts
                $table->refresh();
            }

            if ($guestIndex >= count($guestIds)) {
                break;
            }
        }

        return [
            'assigned' => count($assigned),
            'remaining' => count($guestIds) - count($assigned),
            'assignments' => $assigned,
        ];
    }

    /**
     * Calculate preset seat positions for a table based on its shape.
     */
    public function calculatePresetSeatPositions(SeatTable $table): array
    {
        return match ($table->table_type) {
            'round' => $this->calculateRoundTableSeats($table),
            'rectangular' => $this->calculateRectangularTableSeats($table),
            'square' => $this->calculateSquareTableSeats($table),
            default => $this->calculateRoundTableSeats($table),
        };
    }

    /**
     * Round table: seats evenly distributed around the circumference.
     */
    private function calculateRoundTableSeats(SeatTable $table): array
    {
        $positions = [];
        $radius = min($table->width, $table->height) / 2 + 20; // 20px outside table edge
        $centerX = $table->width / 2;
        $centerY = $table->height / 2;

        for ($i = 0; $i < $table->capacity; $i++) {
            $angle = (360 / $table->capacity) * $i - 90; // Start from top
            $radians = deg2rad($angle);

            $positions[] = [
                'seat_number' => $i + 1,
                'x' => round($centerX + ($radius * cos($radians)), 2),
                'y' => round($centerY + ($radius * sin($radians)), 2),
            ];
        }

        return $positions;
    }

    /**
     * Rectangular table: seats along the top and bottom edges.
     */
    private function calculateRectangularTableSeats(SeatTable $table): array
    {
        $positions = [];
        $seatsPerSide = (int) ceil($table->capacity / 2);
        $spacing = $table->width / ($seatsPerSide + 1);
        $seatNumber = 1;
        $offset = 20; // Distance from table edge

        // Top side
        for ($i = 0; $i < $seatsPerSide && $seatNumber <= $table->capacity; $i++) {
            $positions[] = [
                'seat_number' => $seatNumber++,
                'x' => round($spacing * ($i + 1), 2),
                'y' => -$offset,
            ];
        }

        // Bottom side
        for ($i = 0; $i < $seatsPerSide && $seatNumber <= $table->capacity; $i++) {
            $positions[] = [
                'seat_number' => $seatNumber++,
                'x' => round($spacing * ($i + 1), 2),
                'y' => $table->height + $offset,
            ];
        }

        return $positions;
    }

    /**
     * Square table: seats distributed across all four sides.
     */
    private function calculateSquareTableSeats(SeatTable $table): array
    {
        $positions = [];
        $seatsPerSide = (int) ceil($table->capacity / 4);
        $seatNumber = 1;
        $offset = 20;

        $sides = [
            'top' => ['axis' => 'x', 'size' => $table->width, 'fixedAxis' => 'y', 'fixedValue' => -$offset],
            'right' => ['axis' => 'y', 'size' => $table->height, 'fixedAxis' => 'x', 'fixedValue' => $table->width + $offset],
            'bottom' => ['axis' => 'x', 'size' => $table->width, 'fixedAxis' => 'y', 'fixedValue' => $table->height + $offset],
            'left' => ['axis' => 'y', 'size' => $table->height, 'fixedAxis' => 'x', 'fixedValue' => -$offset],
        ];

        foreach ($sides as $side => $config) {
            $seatsOnThisSide = min($seatsPerSide, $table->capacity - count($positions));
            if ($seatsOnThisSide <= 0) {
                break;
            }

            $spacing = $config['size'] / ($seatsOnThisSide + 1);

            for ($i = 0; $i < $seatsOnThisSide && $seatNumber <= $table->capacity; $i++) {
                $variableValue = round($spacing * ($i + 1), 2);

                $positions[] = [
                    'seat_number' => $seatNumber++,
                    'x' => $config['fixedAxis'] === 'x' ? $config['fixedValue'] : $variableValue,
                    'y' => $config['fixedAxis'] === 'y' ? $config['fixedValue'] : $variableValue,
                ];
            }
        }

        return $positions;
    }

    /**
     * Get resolved seat positions for a table (merging presets with custom positions).
     */
    public function getResolvedSeatPositions(SeatTable $table): array
    {
        $presets = $this->calculatePresetSeatPositions($table);
        $assignments = $table->assignments->keyBy('seat_number');

        $positions = [];

        foreach ($presets as $preset) {
            $seatNum = $preset['seat_number'];
            $assignment = $assignments->get($seatNum);

            $position = [
                'seat_number' => $seatNum,
                'is_custom' => false,
                'x' => $preset['x'],
                'y' => $preset['y'],
                'assignment' => $assignment,
            ];

            // Override with custom position if set
            if ($assignment && $assignment->custom_position) {
                $position['x'] = $assignment->seat_x ?? $preset['x'];
                $position['y'] = $assignment->seat_y ?? $preset['y'];
                $position['is_custom'] = true;
            }

            $positions[] = $position;
        }

        return $positions;
    }

    /**
     * Apply preset positions to all seats on a table (resets custom positions).
     */
    public function applyPresetPositions(SeatTable $table): void
    {
        $presets = $this->calculatePresetSeatPositions($table);
        $presetMap = collect($presets)->keyBy('seat_number');

        foreach ($table->assignments as $assignment) {
            $preset = $presetMap->get($assignment->seat_number);
            if ($preset) {
                $assignment->update([
                    'seat_x' => $preset['x'],
                    'seat_y' => $preset['y'],
                    'custom_position' => false,
                ]);
            }
        }
    }

    /**
     * Calculate statistics for a seat plan.
     */
    public function calculateStats(SeatPlan $seatPlan): array
    {
        $tables = $seatPlan->tables()->with('assignments')->get();

        $totalCapacity = $tables->sum('capacity');
        $totalAssigned = $tables->sum(fn($t) => $t->assignments->count());
        $totalCheckedIn = $tables->sum(fn($t) => $t->assignments->where('checked_in', true)->count());

        $rsvpCounts = [
            'pending' => 0,
            'confirmed' => 0,
            'declined' => 0,
            'tentative' => 0,
        ];

        foreach ($tables as $table) {
            foreach ($table->assignments as $assignment) {
                $rsvpCounts[$assignment->rsvp_status]++;
            }
        }

        $tableStats = $tables->map(fn($table) => [
            'record_id' => $table->record_id,
            'name' => $table->display_name,
            'capacity' => $table->capacity,
            'assigned' => $table->assignments->count(),
            'available' => $table->capacity - $table->assignments->count(),
            'checked_in' => $table->assignments->where('checked_in', true)->count(),
        ]);

        return [
            'total_tables' => $tables->count(),
            'total_capacity' => $totalCapacity,
            'total_assigned' => $totalAssigned,
            'total_available' => $totalCapacity - $totalAssigned,
            'total_checked_in' => $totalCheckedIn,
            'occupancy_rate' => $totalCapacity > 0 ? round(($totalAssigned / $totalCapacity) * 100, 1) : 0,
            'check_in_rate' => $totalAssigned > 0 ? round(($totalCheckedIn / $totalAssigned) * 100, 1) : 0,
            'rsvp_counts' => $rsvpCounts,
            'tables' => $tableStats,
        ];
    }
}
