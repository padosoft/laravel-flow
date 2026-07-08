<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_runs') || Schema::hasColumn('flow_runs', 'definition_version')) {
            return;
        }

        Schema::table('flow_runs', function (Blueprint $table): void {
            $version = $table->unsignedInteger('definition_version')->nullable();
            $checksum = $table->string('definition_checksum', 64)->nullable();

            // Anchor after the replay-lineage column only when it actually
            // exists: on MySQL/MariaDB, ->after() on a missing column fails
            // the ALTER TABLE outright, whereas SQLite/Postgres ignore
            // column placement. A host table that predates or otherwise
            // lacks 2026_05_04_000002 must still get these columns appended.
            if (Schema::hasColumn('flow_runs', 'replayed_from_run_id')) {
                $version->after('replayed_from_run_id');
                $checksum->after('definition_version');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_runs') || ! Schema::hasColumn('flow_runs', 'definition_version')) {
            return;
        }

        Schema::table('flow_runs', function (Blueprint $table): void {
            $table->dropColumn(['definition_version', 'definition_checksum']);
        });
    }
};
