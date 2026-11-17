<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // These employee foreign keys previously used RESTRICT on update.
    private array $references = [
        'user_granted_permissions' => ['user_id'],
        'user_denied_permissions' => ['user_id'],
        'request_forms' => ['employee_id', 'authorized_employee_id', 'approved_by', 'supervisor_id'],
        'feedbacks' => ['employee_id'],
        'storage_product' => ['employee_id'],
        'images' => ['employee_id'],
    ];

    public function up(): void
    {
        $this->changeRule('CASCADE');
    }

    public function down(): void
    {
        $this->changeRule('RESTRICT');
    }

    private function changeRule(string $rule): void
    {
        $keys = DB::select('SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME, r.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.CONSTRAINT_SCHEMA = DATABASE()
              AND k.REFERENCED_TABLE_NAME = ? AND k.REFERENCED_COLUMN_NAME = ?', ['employees', 'id']);
        foreach ($keys as $key) {
            if (! in_array($key->COLUMN_NAME, $this->references[$key->TABLE_NAME] ?? [], true)) {
                continue;
            }
            $quote = fn (string $name) => '`'.str_replace('`', '``', $name).'`';
            $table = $quote($key->TABLE_NAME);
            $constraint = $quote($key->CONSTRAINT_NAME);
            $column = $quote($key->COLUMN_NAME);
            DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$constraint}, ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column}) REFERENCES `employees` (`id`) ON UPDATE {$rule} ON DELETE {$key->DELETE_RULE}");
        }
    }
};
