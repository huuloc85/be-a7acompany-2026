<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_progress', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->string('guide_id', 64);
            $table->unsignedInteger('version');
            $table->unsignedInteger('revision')->default(0);
            $table->json('progress');
            $table->timestamps();
            $table->unique(['employee_id', 'guide_id', 'version'], 'onboarding_user_guide_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_progress');
    }
};
