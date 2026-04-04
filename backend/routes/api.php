<?php

use Illuminate\Support\Facades\Route;
use NewSolari\EventPlanning\Controllers\EventPlanningController;

Route::middleware(['auth.api', 'module.enabled:event-plans', 'partition.app:event-planning-meta-app'])
    ->prefix('api/event-plans')
    ->group(function () {
        Route::middleware(['permission:event-planning.read'])->group(function () {
            Route::get('/', [EventPlanningController::class, 'index']);
            Route::get('/search', [EventPlanningController::class, 'search']);
            Route::get('/stats', [EventPlanningController::class, 'statistics']);
            Route::get('/{id}', [EventPlanningController::class, 'show']);
            Route::get('/{id}/graph', [EventPlanningController::class, 'getGraph']);
            // Calendar endpoints
            Route::get('/{id}/calendar', [EventPlanningController::class, 'calendar']);
            Route::get('/{id}/calendar/day/{date}', [EventPlanningController::class, 'calendarDay']);
            Route::get('/{id}/calendar/week/{date}', [EventPlanningController::class, 'calendarWeek']);
            Route::get('/{id}/calendar/month/{year}/{month}', [EventPlanningController::class, 'calendarMonth']);
            // Recurring patterns (read)
            Route::get('/{id}/recurring-patterns', [EventPlanningController::class, 'listRecurringPatterns']);
            Route::get('/{id}/recurring-patterns/{patternId}/preview', [EventPlanningController::class, 'previewPattern']);
            // Seat plan (read)
            Route::get('/{id}/seat-plans', [EventPlanningController::class, 'listSeatPlans']);
            Route::get('/{id}/seat-plans/{planId}', [EventPlanningController::class, 'showSeatPlan']);
            Route::get('/{id}/seat-plans/{planId}/stats', [EventPlanningController::class, 'seatPlanStats']);
        });
        Route::middleware(['permission:event-planning.create'])->group(function () {
            Route::post('/', [EventPlanningController::class, 'store']);
        });
        Route::middleware(['permission:event-planning.update'])->group(function () {
            Route::put('/{id}', [EventPlanningController::class, 'update']);
            // Node operations
            Route::post('/{id}/nodes', [EventPlanningController::class, 'storeNode']);
            // API-MED-NEW-007: Bulk endpoints use idempotency middleware for retry safety
            Route::post('/{id}/nodes/bulk', [EventPlanningController::class, 'bulkAddNodes'])->middleware('idempotent');
            Route::put('/{id}/nodes/positions', [EventPlanningController::class, 'batchUpdatePositions'])->middleware('idempotent');
            Route::put('/{id}/nodes/{nodeId}', [EventPlanningController::class, 'updateNode']);
            Route::delete('/{id}/nodes/{nodeId}', [EventPlanningController::class, 'destroyNode']);
            // Connection operations
            Route::post('/{id}/connections', [EventPlanningController::class, 'storeConnection']);
            Route::put('/{id}/connections/{connId}', [EventPlanningController::class, 'updateConnection']);
            Route::delete('/{id}/connections/{connId}', [EventPlanningController::class, 'destroyConnection']);
            // Drawing operations
            Route::post('/{id}/drawings', [EventPlanningController::class, 'storeDrawing']);
            Route::delete('/{id}/drawings/{drawingId}', [EventPlanningController::class, 'destroyDrawing']);
            // Recurring pattern operations
            Route::post('/{id}/recurring-patterns', [EventPlanningController::class, 'storeRecurringPattern']);
            Route::put('/{id}/recurring-patterns/{patternId}', [EventPlanningController::class, 'updateRecurringPattern']);
            Route::delete('/{id}/recurring-patterns/{patternId}', [EventPlanningController::class, 'destroyRecurringPattern']);
            Route::post('/{id}/recurring-patterns/{patternId}/generate', [EventPlanningController::class, 'generateFromPattern']);
            // Seat plan operations
            Route::post('/{id}/seat-plans', [EventPlanningController::class, 'storeSeatPlan']);
            Route::put('/{id}/seat-plans/{planId}', [EventPlanningController::class, 'updateSeatPlan']);
            Route::delete('/{id}/seat-plans/{planId}', [EventPlanningController::class, 'destroySeatPlan']);
            Route::post('/{id}/seat-plans/{planId}/auto-arrange', [EventPlanningController::class, 'autoArrangeTables']);
            // Table operations
            Route::post('/{id}/seat-plans/{planId}/tables', [EventPlanningController::class, 'storeSeatTable']);
            // API-MED-NEW-007: Bulk endpoints use idempotency middleware for retry safety
            Route::put('/{id}/seat-plans/{planId}/tables/positions', [EventPlanningController::class, 'batchUpdateTablePositions'])->middleware('idempotent');
            Route::put('/{id}/seat-plans/{planId}/tables/{tableId}', [EventPlanningController::class, 'updateSeatTable']);
            Route::delete('/{id}/seat-plans/{planId}/tables/{tableId}', [EventPlanningController::class, 'destroySeatTable']);
            // Seat assignment operations
            Route::post('/{id}/seat-plans/{planId}/assignments', [EventPlanningController::class, 'storeSeatAssignment']);
            // API-MED-NEW-007: Bulk endpoints use idempotency middleware for retry safety
            Route::post('/{id}/seat-plans/{planId}/assignments/bulk', [EventPlanningController::class, 'bulkAssignSeats'])->middleware('idempotent');
            Route::post('/{id}/seat-plans/{planId}/auto-assign', [EventPlanningController::class, 'autoAssignSeats']);
            Route::put('/{id}/seat-plans/{planId}/assignments/{assignmentId}', [EventPlanningController::class, 'updateSeatAssignment']);
            Route::delete('/{id}/seat-plans/{planId}/assignments/{assignmentId}', [EventPlanningController::class, 'destroySeatAssignment']);
            Route::post('/{id}/seat-plans/{planId}/check-in/{assignmentId}', [EventPlanningController::class, 'checkInGuest']);
            Route::post('/{id}/seat-plans/{planId}/undo-check-in/{assignmentId}', [EventPlanningController::class, 'undoCheckIn']);
            // Seat position operations (assignment-based - for assigned seats)
            Route::put('/{id}/seat-plans/{planId}/assignments/{assignmentId}/position', [EventPlanningController::class, 'updateSeatPosition']);
            Route::post('/{id}/seat-plans/{planId}/assignments/{assignmentId}/reset-position', [EventPlanningController::class, 'resetSeatPosition']);
            Route::get('/{id}/seat-plans/{planId}/tables/{tableId}/seats', [EventPlanningController::class, 'getTableWithSeats']);
            Route::post('/{id}/seat-plans/{planId}/tables/{tableId}/apply-preset', [EventPlanningController::class, 'applyPresetPositions']);

            // Table seat position operations (for any seat - assigned or unassigned)
            Route::put('/{id}/seat-plans/{planId}/tables/{tableId}/seat-position', [EventPlanningController::class, 'updateTableSeatPosition']);
            Route::post('/{id}/seat-plans/{planId}/tables/{tableId}/reset-seat-positions', [EventPlanningController::class, 'resetTableSeatPositions']);
        });
        Route::middleware(['permission:event-planning.delete'])->group(function () {
            Route::delete('/{id}', [EventPlanningController::class, 'destroy']);
        });
    });
