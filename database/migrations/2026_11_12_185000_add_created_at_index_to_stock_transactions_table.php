<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('stock_transactions', 'stock_transactions_created_at_index')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_transactions ADD INDEX stock_transactions_created_at_index (created_at), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }

        Schema::table('stock_transactions', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transactions', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
