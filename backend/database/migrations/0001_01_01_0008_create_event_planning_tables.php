<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for EVENT_PLANNING module tables.
 * Auto-generated from schema dump.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_plan_connections', function (Blueprint $table) {
            $table->string('record_id', 36)->primary();
            $table->string('partition_id', 36);
            $table->string('event_plan_id', 36);
            $table->string('from_node_id', 36);
            $table->string('to_node_id', 36);
            $table->string('from_side', 20)->default('right');
            $table->string('to_side', 20)->default('left');
            $table->string('style', 20)->default('solid');
            $table->string('path_type', 20)->default('curved');
            $table->string('color', 7)->default('#6b7280');
            $table->decimal('thickness', 3, 1)->default(2.0);
            $table->string('arrow_type', 20)->default('none');
            $table->string('relationship_type', 64)->nullable();
            $table->string('relationship_label')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamps();
            $table->index('event_plan_id', 'event_plan_connections_event_plan_id_index');
            $table->index('from_node_id', 'event_plan_connections_from_node_id_index');
            $table->index('to_node_id', 'event_plan_connections_to_node_id_index');
            $table->index('partition_id', 'event_plan_connections_partition_id_foreign');
            $table->foreign('event_plan_id')->references('record_id')->on('event_plans')->onDelete('cascade');
            $table->foreign('from_node_id')->references('record_id')->on('event_plan_nodes')->onDelete('cascade');
            // Cross-module FK skipped: event_plan_connections_partition_id_foreign -> identity_partitions.record_id
            $table->foreign('to_node_id')->references('record_id')->on('event_plan_nodes')->onDelete('cascade');
        });

        Schema::create('event_plan_drawings', function (Blueprint $table) {
            $table->string('record_id', 36)->primary();
            $table->string('partition_id', 36);
            $table->string('event_plan_id', 36);
            $table->string('tool', 20);
            $table->json('points');
            $table->string('color', 7)->default('#000000');
            $table->integer('size')->default(2);
            $table->string('line_style', 10)->nullable();
            $table->integer('thickness')->nullable();
            $table->string('arrow_type', 10)->nullable();
            $table->string('text', 500)->nullable();
            $table->integer('z_index')->default(0);
            $table->boolean('deleted')->default(false);
            $table->timestamps();
            $table->index('event_plan_id', 'event_plan_drawings_event_plan_id_index');
            $table->index('partition_id', 'event_plan_drawings_partition_id_foreign');
            $table->foreign('event_plan_id')->references('record_id')->on('event_plans')->onDelete('cascade');
            // Cross-module FK skipped: event_plan_drawings_partition_id_foreign -> identity_partitions.record_id
        });

        Schema::create('event_plan_nodes', function (Blueprint $table) {
            $table->string('record_id', 36)->primary();
            $table->string('partition_id', 36);
            $table->string('event_plan_id', 36);
            $table->string('entity_type', 64);
            $table->string('entity_id', 36);
            $table->decimal('x', 10, 2)->default(0.00);
            $table->decimal('y', 10, 2)->default(0.00);
            $table->decimal('width', 10, 2)->default(200.00);
            $table->decimal('height', 10, 2)->default(100.00);
            $table->integer('z_index')->default(0);
            $table->json('style')->nullable();
            $table->string('label_override')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_collapsed')->default(false);
            $table->boolean('deleted')->default(false);
            $table->timestamps();
            $table->unique(['event_plan_id', 'entity_type', 'entity_id'], 'unique_entity_per_plan');
            $table->index('event_plan_id', 'event_plan_nodes_event_plan_id_index');
            $table->index('partition_id', 'event_plan_nodes_partition_id_foreign');
            $table->foreign('event_plan_id')->references('record_id')->on('event_plans');
            // Cross-module FK skipped: event_plan_nodes_partition_id_foreign -> identity_partitions.record_id
        });

        Schema::create('event_plans', function (Blueprint $table) {
            $table->string('record_id', 36)->primary();
            $table->string('partition_id', 36);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('event_type', 50)->nullable();
            $table->string('status', 20)->default('planning');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('timezone', 50)->default('UTC');
            $table->string('venue_place_id', 36)->nullable();
            $table->string('organizer_entity_id', 36)->nullable();
            $table->integer('expected_guests')->default(0);
            $table->string('budget_id', 36)->nullable();
            $table->json('canvas_state')->nullable();
            $table->string('default_view', 20)->default('calendar');
            $table->boolean('is_public')->default(false);
            $table->text('notes')->nullable();
            $table->string('created_by', 36);
            $table->string('updated_by', 36)->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamps();
            $table->index('partition_id', 'event_plans_partition_id_index');
            $table->index('status', 'event_plans_status_index');
            $table->index(['start_date', 'end_date'], 'event_plans_start_date_end_date_index');
            $table->index('budget_id', 'event_plans_budget_id_index');
            $table->index('venue_place_id', 'event_plans_venue_place_id_index');
            $table->index('updated_by', 'event_plans_updated_by_foreign');
            $table->index('created_by', 'event_plans_created_by_foreign');
            // Cross-module FK skipped: event_plans_budget_id_foreign -> budgets.record_id
            // Cross-module FK skipped: event_plans_created_by_foreign -> identity_users.record_id
            // Cross-module FK skipped: event_plans_partition_id_foreign -> identity_partitions.record_id
            // Cross-module FK skipped: event_plans_updated_by_foreign -> identity_users.record_id
        });

        Schema::create('seat_assignments', function (Blueprint $table) {
            $table->string('record_id', 36)->primary();
            $table->string('partition_id', 36);
            $table->string('seat_table_id', 36);
            $table->string('person_id', 36)->nullable();
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->integer('seat_number');
            $table->decimal('seat_x', 8, 2)->nullable();
            $table->decimal('seat_y', 8, 2)->nullable();
            $table->boolean('custom_position')->default(false);
            $table->text('dietary_requirements')->nullable();
            $table->text('accessibility_needs')->nullable();
            $table->text('notes')->nullable();
            $table->string('rsvp_status', 20)->default('pending');
            $table->boolean('checked_in')->default(false);
            $table->timestamp('checked_in_at')->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamps();
            $table->unique(['seat_table_id', 'seat_number'], 'seat_assignments_seat_table_id_seat_number_unique');
            $table->index('partition_id', 'seat_assignments_partition_id_index');
            $table->index('seat_table_id', 'seat_assignments_seat_table_id_index');
            $table->index('person_id', 'seat_assignments_person_id_index');
            // Cross-module FK skipped: seat_assignments_partition_id_foreign -> identity_partitions.record_id
            $table->foreign('seat_table_id')->references('record_id')->on('seat_tables')->onDelete('cascade');
        });

        Schema::create('seat_plans', function (Blueprint $table) {
            $table->string('record_id', 36)->primary();
            $table->string('partition_id', 36);
            $table->string('event_plan_id', 36);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('layout_type', 20)->default('banquet');
            $table->json('canvas_state')->nullable();
            $table->string('floor_plan_image_id', 36)->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamps();
            $table->index('partition_id', 'seat_plans_partition_id_index');
            $table->index('event_plan_id', 'seat_plans_event_plan_id_index');
            $table->foreign('event_plan_id')->references('record_id')->on('event_plans')->onDelete('cascade');
            // Cross-module FK skipped: seat_plans_partition_id_foreign -> identity_partitions.record_id
        });

        Schema::create('seat_tables', function (Blueprint $table) {
            $table->string('record_id', 36)->primary();
            $table->string('partition_id', 36);
            $table->string('seat_plan_id', 36);
            $table->string('name', 100);
            $table->integer('table_number')->nullable();
            $table->string('table_type', 20)->default('round');
            $table->integer('capacity')->default(8);
            $table->decimal('x', 10, 2)->default(0.00);
            $table->decimal('y', 10, 2)->default(0.00);
            $table->decimal('rotation', 5, 2)->default(0.00);
            $table->decimal('width', 10, 2)->default(100.00);
            $table->decimal('height', 10, 2)->default(100.00);
            $table->string('color', 7)->default('#e5e7eb');
            $table->json('custom_seat_positions')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamps();
            $table->index('partition_id', 'seat_tables_partition_id_index');
            $table->index('seat_plan_id', 'seat_tables_seat_plan_id_index');
            // Cross-module FK skipped: seat_tables_partition_id_foreign -> identity_partitions.record_id
            $table->foreign('seat_plan_id')->references('record_id')->on('seat_plans')->onDelete('cascade');
        });

        // Archive table
        Schema::create('event_plan_connections_archive', function (Blueprint $table) {
            $table->bigIncrements('archive_id');
            $table->string('original_record_id', 36);
            $table->string('partition_id', 36);
            $table->string('event_plan_id', 36);
            $table->string('from_node_id', 36);
            $table->string('to_node_id', 36);
            $table->string('from_side', 20);
            $table->string('to_side', 20);
            $table->string('style', 20);
            $table->string('path_type', 20);
            $table->string('color', 7);
            $table->decimal('thickness', 3, 1)->default(2.0);
            $table->string('arrow_type', 20);
            $table->string('relationship_type', 64)->nullable();
            $table->string('relationship_label')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamp('archived_at')->useCurrent();
            $table->string('archived_by', 64)->default('system-archive-daemon');
            $table->timestamps();
            $table->index(['partition_id', 'original_record_id'], 'idx_event_plan_connections_archive_partition_record');
            $table->index('archived_at', 'idx_event_plan_connections_archive_archived_at');
            $table->index('original_record_id', 'event_plan_connections_archive_original_record_id_index');
        });

        // Archive table
        Schema::create('event_plan_drawings_archive', function (Blueprint $table) {
            $table->bigIncrements('archive_id');
            $table->string('original_record_id', 36);
            $table->string('partition_id', 36);
            $table->string('event_plan_id', 36);
            $table->string('tool', 20);
            $table->json('points');
            $table->string('color', 7);
            $table->integer('size')->default(2);
            $table->string('line_style', 10)->nullable();
            $table->integer('thickness')->nullable();
            $table->string('arrow_type', 10)->nullable();
            $table->string('text', 500)->nullable();
            $table->integer('z_index')->default(0);
            $table->boolean('deleted')->default(false);
            $table->timestamp('archived_at')->useCurrent();
            $table->string('archived_by', 64)->default('system-archive-daemon');
            $table->timestamps();
            $table->index(['partition_id', 'original_record_id'], 'idx_event_plan_drawings_archive_partition_record');
            $table->index('archived_at', 'idx_event_plan_drawings_archive_archived_at');
            $table->index('original_record_id', 'event_plan_drawings_archive_original_record_id_index');
        });

        // Archive table
        Schema::create('event_plan_nodes_archive', function (Blueprint $table) {
            $table->bigIncrements('archive_id');
            $table->string('original_record_id', 36);
            $table->string('partition_id', 36);
            $table->string('event_plan_id', 36);
            $table->string('entity_type', 64);
            $table->string('entity_id', 36);
            $table->decimal('x', 10, 2)->default(0.00);
            $table->decimal('y', 10, 2)->default(0.00);
            $table->decimal('width', 10, 2)->default(200.00);
            $table->decimal('height', 10, 2)->default(100.00);
            $table->integer('z_index')->default(0);
            $table->json('style')->nullable();
            $table->string('label_override')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_collapsed')->default(false);
            $table->boolean('deleted')->default(false);
            $table->timestamp('archived_at')->useCurrent();
            $table->string('archived_by', 64)->default('system-archive-daemon');
            $table->timestamps();
            $table->index(['partition_id', 'original_record_id'], 'idx_event_plan_nodes_archive_partition_record');
            $table->index('archived_at', 'idx_event_plan_nodes_archive_archived_at');
            $table->index('original_record_id', 'event_plan_nodes_archive_original_record_id_index');
        });

        // Archive table
        Schema::create('event_plans_archive', function (Blueprint $table) {
            $table->bigIncrements('archive_id');
            $table->string('original_record_id', 36);
            $table->string('partition_id', 36);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('event_type', 50)->nullable();
            $table->string('status', 20);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('timezone', 50);
            $table->string('venue_place_id', 36)->nullable();
            $table->string('organizer_entity_id', 36)->nullable();
            $table->integer('expected_guests')->default(0);
            $table->string('budget_id', 36)->nullable();
            $table->json('canvas_state')->nullable();
            $table->string('default_view', 20);
            $table->boolean('is_public')->default(false);
            $table->text('notes')->nullable();
            $table->string('created_by', 36);
            $table->string('updated_by', 36)->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamp('archived_at')->useCurrent();
            $table->string('archived_by', 64)->default('system-archive-daemon');
            $table->timestamps();
            $table->index(['partition_id', 'original_record_id'], 'idx_event_plans_archive_partition_record');
            $table->index('archived_at', 'idx_event_plans_archive_archived_at');
            $table->index('original_record_id', 'event_plans_archive_original_record_id_index');
        });

        // Archive table
        Schema::create('seat_assignments_archive', function (Blueprint $table) {
            $table->bigIncrements('archive_id');
            $table->string('original_record_id', 36);
            $table->string('partition_id', 36);
            $table->string('seat_table_id', 36);
            $table->string('person_id', 36)->nullable();
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->integer('seat_number');
            $table->decimal('seat_x', 8, 2)->nullable();
            $table->decimal('seat_y', 8, 2)->nullable();
            $table->boolean('custom_position')->default(false);
            $table->text('dietary_requirements')->nullable();
            $table->text('accessibility_needs')->nullable();
            $table->text('notes')->nullable();
            $table->string('rsvp_status', 20);
            $table->boolean('checked_in')->default(false);
            $table->timestamp('checked_in_at')->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamp('archived_at')->useCurrent();
            $table->string('archived_by', 64)->default('system-archive-daemon');
            $table->timestamps();
            $table->index(['partition_id', 'original_record_id'], 'idx_seat_assignments_archive_partition_record');
            $table->index('archived_at', 'idx_seat_assignments_archive_archived_at');
            $table->index('original_record_id', 'seat_assignments_archive_original_record_id_index');
        });

        // Archive table
        Schema::create('seat_plans_archive', function (Blueprint $table) {
            $table->bigIncrements('archive_id');
            $table->string('original_record_id', 36);
            $table->string('partition_id', 36);
            $table->string('event_plan_id', 36);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('layout_type', 20);
            $table->json('canvas_state')->nullable();
            $table->string('floor_plan_image_id', 36)->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamp('archived_at')->useCurrent();
            $table->string('archived_by', 64)->default('system-archive-daemon');
            $table->timestamps();
            $table->index(['partition_id', 'original_record_id'], 'idx_seat_plans_archive_partition_record');
            $table->index('archived_at', 'idx_seat_plans_archive_archived_at');
            $table->index('original_record_id', 'seat_plans_archive_original_record_id_index');
        });

        // Archive table
        Schema::create('seat_tables_archive', function (Blueprint $table) {
            $table->bigIncrements('archive_id');
            $table->string('original_record_id', 36);
            $table->string('partition_id', 36);
            $table->string('seat_plan_id', 36);
            $table->string('name', 100);
            $table->integer('table_number')->nullable();
            $table->string('table_type', 20);
            $table->integer('capacity')->default(8);
            $table->decimal('x', 10, 2)->default(0.00);
            $table->decimal('y', 10, 2)->default(0.00);
            $table->decimal('rotation', 5, 2)->default(0.00);
            $table->decimal('width', 10, 2)->default(100.00);
            $table->decimal('height', 10, 2)->default(100.00);
            $table->string('color', 7);
            $table->json('custom_seat_positions')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('deleted')->default(false);
            $table->timestamp('archived_at')->useCurrent();
            $table->string('archived_by', 64)->default('system-archive-daemon');
            $table->timestamps();
            $table->index(['partition_id', 'original_record_id'], 'idx_seat_tables_archive_partition_record');
            $table->index('archived_at', 'idx_seat_tables_archive_archived_at');
            $table->index('original_record_id', 'seat_tables_archive_original_record_id_index');
        });

        if (!Schema::hasTable('recurring_event_patterns')) {
            Schema::create('recurring_event_patterns', function (Blueprint $table) {
                $table->string('record_id', 36)->primary();
                $table->string('partition_id', 36);
                $table->string('event_plan_id', 36);
                $table->string('name');
                $table->string('recurrence_type', 20);
                $table->integer('interval_value')->default(1);
                $table->json('days_of_week')->nullable();
                $table->integer('day_of_month')->nullable();
                $table->integer('week_of_month')->nullable();
                $table->integer('month_of_year')->nullable();
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->integer('max_occurrences')->nullable();
                $table->json('event_template');
                $table->date('last_generated_date')->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamps();
                $table->index('event_plan_id', 'recurring_event_patterns_event_plan_id_index');
                $table->index('partition_id', 'recurring_event_patterns_partition_id_foreign');
            });
        }

        if (!Schema::hasTable('recurring_event_patterns_archive')) {
            Schema::create('recurring_event_patterns_archive', function (Blueprint $table) {
                $table->bigIncrements('archive_id');
                $table->string('original_record_id', 36);
                $table->string('partition_id', 36);
                $table->string('event_plan_id', 36);
                $table->string('name');
                $table->string('recurrence_type', 20);
                $table->integer('interval_value')->default(1);
                $table->json('days_of_week')->nullable();
                $table->integer('day_of_month')->nullable();
                $table->integer('week_of_month')->nullable();
                $table->integer('month_of_year')->nullable();
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->integer('max_occurrences')->nullable();
                $table->json('event_template');
                $table->date('last_generated_date')->nullable();
                $table->boolean('deleted')->default(false);
                $table->timestamp('archived_at')->useCurrent();
                $table->string('archived_by', 64)->default('system-archive-daemon');
                $table->timestamps();
                $table->index(['partition_id', 'original_record_id'], 'idx_recurring_event_patterns_archive_partition_record');
                $table->index('archived_at', 'idx_recurring_event_patterns_archive_archived_at');
                $table->index('original_record_id', 'recurring_event_patterns_archive_original_record_id_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('seat_tables_archive');
        Schema::dropIfExists('seat_plans_archive');
        Schema::dropIfExists('seat_assignments_archive');
        Schema::dropIfExists('event_plans_archive');
        Schema::dropIfExists('event_plan_nodes_archive');
        Schema::dropIfExists('event_plan_drawings_archive');
        Schema::dropIfExists('event_plan_connections_archive');
        Schema::dropIfExists('seat_tables');
        Schema::dropIfExists('seat_plans');
        Schema::dropIfExists('seat_assignments');
        Schema::dropIfExists('event_plans');
        Schema::dropIfExists('event_plan_nodes');
        Schema::dropIfExists('event_plan_drawings');
        Schema::dropIfExists('event_plan_connections');
        Schema::dropIfExists('recurring_event_patterns_archive');
        Schema::dropIfExists('recurring_event_patterns');
        Schema::enableForeignKeyConstraints();
    }
};
