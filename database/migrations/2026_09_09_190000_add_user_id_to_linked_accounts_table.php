<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('linked_accounts', 'user_id')) {
            Schema::table('linked_accounts', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
                $table->unique('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('linked_accounts', 'user_id')) {
            Schema::table('linked_accounts', function (Blueprint $table) {
                $table->dropUnique(['user_id']);
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};