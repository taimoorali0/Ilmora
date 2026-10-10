<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Writes one row to audit_logs for actions taken outside the sync path (admin panel, server endpoints). */
final class Audit
{
    public static function log(string $action, string $table, ?string $rowId, ?string $schoolId, ?array $before = null, ?array $after = null): void
    {
        DB::table('audit_logs')->insert([
            'school_id' => $schoolId, 'user_id' => auth()->id(), 'action' => $action, 'table_name' => $table,
            'row_id' => $rowId, 'before' => $before ? json_encode($before) : null, 'after' => $after ? json_encode($after) : null,
            'ip' => request()?->ip(), 'created_at' => now(),
        ]);
    }
}
