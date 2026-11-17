<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_code_transfers', function (Blueprint $table) {
            $table->id();
            // Physical device codes and historical aliases must never cascade or be deleted with employees.
            $table->string('old_code', 20)->unique();
            $table->string('new_code', 20)->index();
            $table->string('employee_name');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedBigInteger('history_import_id')->nullable();
            $table->boolean('target_reserved')->default(false);
            $table->unsignedInteger('fingerprint_count')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Keep historical aliases: removing them would orphan future imports of old device events.
        throw new RuntimeException('Không tự xóa ánh xạ mã nhân viên. Liên hệ IT nếu cần hoàn tác.');
    }
};
