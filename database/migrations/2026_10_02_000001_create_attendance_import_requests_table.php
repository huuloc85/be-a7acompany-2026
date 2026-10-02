<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_import_requests', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');
            $table->unique(['start_date', 'end_date']);
            $table->string('requested_by', 20);
            $table->string('status', 20)->default('pending')->index();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedInteger('completed_days')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('existing')->default(0);
            $table->unsignedInteger('unmatched')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->json('unmatched_codes')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_import_requests');
    }
};
