<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_archive_state', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->date('archived_before');
            $table->timestamp('archived_at');
            $table->unsignedBigInteger('source_count');
            $table->unsignedBigInteger('deleted_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_archive_state');
    }
};
