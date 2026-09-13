<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('collaborator_user_id')->nullable()->index();
            $table->string('url');
            $table->string('business_name')->nullable();
            $table->string('activity_sector')->nullable();
            $table->json('key_offerings')->nullable();
            $table->string('brand_tone')->nullable();
            $table->json('raw_metadata')->nullable();
            $table->timestamps();

            $table->index(['url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_profiles');
    }
};

