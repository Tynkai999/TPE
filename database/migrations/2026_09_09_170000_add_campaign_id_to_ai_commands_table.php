<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ai_commands', 'campaign_id')) {
            Schema::table('ai_commands', function (Blueprint $table) {
                $table->uuid('campaign_id')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ai_commands', 'campaign_id')) {
            Schema::table('ai_commands', function (Blueprint $table) {
                $table->dropColumn('campaign_id');
            });
        }
    }
};