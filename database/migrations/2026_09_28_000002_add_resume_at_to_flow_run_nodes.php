<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_run_nodes') || Schema::hasColumn('flow_run_nodes', 'resume_at')) {
            return;
        }

        Schema::table('flow_run_nodes', function (Blueprint $table): void {
            // When a TIMER node (NodeResult::pausedUntil()) is due to resume.
            // NULL for every other node, including an approval pause — which is
            // how the resume sweeper tells the two kinds of `paused` apart. This
            // is deliberately NOT `available_at`: that column records a retry
            // backoff and is set on any node that retried before pausing.
            $table->timestampTz('resume_at')->nullable();
            $table->index(['status', 'resume_at'], 'flow_run_nodes_status_resume_at_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_run_nodes') || ! Schema::hasColumn('flow_run_nodes', 'resume_at')) {
            return;
        }

        Schema::table('flow_run_nodes', function (Blueprint $table): void {
            $table->dropIndex('flow_run_nodes_status_resume_at_index');
            $table->dropColumn('resume_at');
        });
    }
};
