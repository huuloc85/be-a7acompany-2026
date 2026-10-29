<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cap7a_daily_starts', function (Blueprint $table) {
            $table->id();
            $table->string('system', 10);
            $table->date('date');
            $table->unsignedBigInteger('started_at_ms');
            $table->timestamps();
            $table->unique(['system', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cap7a_daily_starts');
    }
};
