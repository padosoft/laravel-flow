<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_node_children')) {
            return;
        }

        Schema::create('flow_node_children', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 36);            // parent run
            $table->string('parent_node_id');        // the fan-out / sub-flow node in the parent graph
            $table->string('child_run_id', 36);      // the spawned child graph run
            $table->unsignedInteger('child_index');  // position in the fan-out (ordered join)
            $table->string('status', 32);            // NodeState->value of the child run outcome
            $table->json('outputs')->nullable();     // child run output (redacted), aggregated on join
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['run_id', 'parent_node_id', 'child_index']);
            // One ledger row per child run (1:1) — keeps findByChildRun() /
            // completeChild() unambiguous under any duplicate-insert attempt.
            $table->unique('child_run_id');
            $table->foreign('run_id')->references('id')->on('flow_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_node_children');
    }
};
