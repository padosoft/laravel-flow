<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_run_nodes') || Schema::hasColumn('flow_run_nodes', 'active_ports')) {
            return;
        }

        Schema::table('flow_run_nodes', function (Blueprint $table): void {
            // The output ports a BRANCHING node activated (NodeResult::branch()),
            // or [] for a node skipped because every incoming wire was dead.
            // NULL for every ordinary node — all of its ports are live — so the
            // column is only ever written by a graph that branches.
            $table->json('active_ports')->nullable()->after('outputs');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_run_nodes') || ! Schema::hasColumn('flow_run_nodes', 'active_ports')) {
            return;
        }

        Schema::table('flow_run_nodes', function (Blueprint $table): void {
            $table->dropColumn('active_ports');
        });
    }
};
