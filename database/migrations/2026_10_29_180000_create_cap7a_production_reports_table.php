<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cap7a_production_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_key', 64)->unique();
            $table->string('system', 10);
            $table->string('recipient');
            $table->json('state');
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cap7a_production_reports');
    }
};
