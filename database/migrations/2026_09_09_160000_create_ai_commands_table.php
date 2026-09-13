<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid('collaborator_user_id');
            $table->string('intent');
            $table->json('parameters');
            $table->string('status')->default('PROPOSED');
            $table->uuid('campaign_id')->nullable();
            $table->unsignedInteger('estimated_recipients')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['collaborator_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_commands');
    }
};
