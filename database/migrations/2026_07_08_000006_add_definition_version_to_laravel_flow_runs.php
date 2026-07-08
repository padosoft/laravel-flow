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
            $table->unsignedInteger('definition_version')->nullable()->after('replayed_from_run_id');
            $table->string('definition_checksum', 64)->nullable()->after('definition_version');
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
