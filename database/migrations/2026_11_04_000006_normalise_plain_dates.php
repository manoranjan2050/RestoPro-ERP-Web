<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Strip "00:00:00" time parts that older code wrote into date columns (only matters on SQLite). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['expenses' => ['paid_on'], 'staff' => ['joining_date', 'leaving_date']] as $table => $cols) {
            foreach ($cols as $col) {
                DB::table($table)->whereNotNull($col)->update([$col => DB::raw("SUBSTR({$col}, 1, 10)")]);
            }
        }
    }

    public function down(): void {}
};
