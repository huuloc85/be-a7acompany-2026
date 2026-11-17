<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('send_stamps', function (Blueprint $table) {
            $table->integer('linked_product_id')->nullable();
            $table->foreign('linked_product_id')->references('id')->on('products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('send_stamps', function (Blueprint $table) {
            $table->dropForeign(['linked_product_id']);
            $table->dropColumn('linked_product_id');
        });
    }
};
