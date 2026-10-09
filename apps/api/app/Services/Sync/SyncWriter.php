<?php

namespace App\Services\Sync;

use App\Models\Device;
use App\Models\User;
use App\Services\Finance\PaymentRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies a batch of client CRUD operations uploaded by PowerSync
 * (docs/decisions.md D2, D3, D7).
 *
 * Each op: {op: PUT|PATCH|DELETE, table, id, data, base_version?}
 * Result per op: applied | ignored_duplicate | merged | conflict | rejected
 *
 * Security: school_id is always taken from the authenticated user, never from the client.
 */
final class SyncWriter
{
    /** Columns a client may never set. */
    private const SERVER_COLUMNS = ['school_id', 'version', 'created_by', 'updated_by', 'created_at',
        'updated_at', 'device_id', 'sync_state'];

    public function __construct(private readonly PaymentRecorder $payments) {}

    /** @return array<int, array{id:string, table:string, result:string, message?:string}> */
    public function apply(User $user, Device $device, array $ops): array
    {
        $results = [];
        foreach ($ops as $op) {
            $results[] = DB::transaction(fn () => $this->applyOne($user, $device, $op));
        }
        return $results;
    }

    private function applyOne(User $user, Device $device, array $op): array
    {
        $table = (string) ($op['table'] ?? '');
        $id = (string) ($op['id'] ?? '');
        $res = fn (string $r, ?string $m = null) => array_filter(['id' => $id, 'table' => $table, 'result' => $r, 'message' => $m]);

        $rule = config("ilmora.sync_tables.$table");
        if (! $rule || $rule['mode'] === 'server') {
            return $res('rejected', 'Table is not writable from devices.');
        }
        if (! Str::isUuid($id)) {
            return $res('rejected', 'Row id must be a UUID generated on the device.');
        }
        if (! $user->school?->canWrite()) {
            return $res('rejected', 'School is not accepting changes (suspended or read-only).');
        }

        $data = array_diff_key((array) ($op['data'] ?? []), array_flip(self::SERVER_COLUMNS));
        $branchId = $data['branch_id'] ?? DB::table($table)->where('id', $id)->value('branch_id');
        if (! $user->canDo($rule['ability'], $branchId)) {
            return $res('rejected', 'Not permitted.');
        }

        $existing = DB::table($table)->where('id', $id)->first();
        if ($existing && $existing->school_id !== $user->school_id) {
            return $res('rejected', 'Not found.'); // never reveal other schools' rows
        }

        return match ($rule['mode']) {
            'append' => $this->append($user, $device, $table, $id, $data, $existing, $res),
            'mergeable', 'protected' => $this->upsert($user, $device, $rule['mode'], $op, $table, $id, $data, $existing, $res),
        };
    }

    private function append(User $user, Device $device, string $table, string $id, array $data, ?object $existing, callable $res): array
    {
        if ($existing) {
            return $res('ignored_duplicate'); // retry of an already accepted write
        }
        $row = $data + [
            'id' => $id, 'school_id' => $user->school_id, 'created_by' => $user->id,
            'device_id' => $device->id, 'created_at' => now(),
        ];
        DB::table($table)->insert($row);
        $this->audit($user, $device, 'create', $table, $id, null, $row);

        if ($table === 'payments') {
            $this->payments->afterInsert($id);
        }
        return $res('applied');
    }

    private function upsert(User $user, Device $device, string $mode, array $op, string $table, string $id, array $data, ?object $existing, callable $res): array
    {
        $kind = strtoupper($op['op'] ?? 'PUT');

        if (! $existing) {
            if ($kind === 'DELETE') {
                return $res('ignored_duplicate');
            }
            $row = $data + ['id' => $id, 'school_id' => $user->school_id, 'version' => 1,
                'created_by' => $user->id, 'updated_by' => $user->id, 'created_at' => now(), 'updated_at' => now()];
            try {
                // Nested transaction = savepoint, so a duplicate does not abort the outer transaction (Postgres).
                DB::transaction(fn () => DB::table($table)->insert($row));
            } catch (UniqueConstraintViolationException) {
                // Another device created the same natural record (e.g. same student + date)
                // under a different id. Keep the server row; record the client's version for review.
                $this->conflict($user, $device, $table, $id, 0, 0, $data, []);
                return $res('conflict', 'Same record already created on another device; sent for review.');
            }
            $this->audit($user, $device, 'create', $table, $id, null, $row);
            return $res('applied');
        }

        if ($kind === 'DELETE') {
            // Soft rules: deletes of synced records go through status fields, not row deletes.
            return $res('rejected', 'Delete is not allowed; change status instead.');
        }

        $base = (int) ($op['base_version'] ?? 0);
        $serverRow = (array) $existing;

        if ($base !== (int) $existing->version) {
            $changed = $this->changedSince($table, $id, $base);
            $overlap = array_intersect(array_keys($data), $changed);
            if ($mode === 'protected' || $base === 0 || $overlap) {
                $this->conflict($user, $device, $table, $id, $base, (int) $existing->version, $data, $serverRow);
                return $res('conflict', 'Record changed on another device; sent for review.');
            }
            $result = 'merged';
        } else {
            $result = 'applied';
        }

        $update = $data + ['version' => $existing->version + 1, 'updated_by' => $user->id, 'updated_at' => now()];
        DB::table($table)->where('id', $id)->update($update);
        $this->audit($user, $device, 'update', $table, $id, $serverRow, $update);
        return $res($result);
    }

    private function conflict(User $user, Device $device, string $table, string $id, int $base, int $serverVersion, array $data, array $serverRow): void
    {
        DB::table('sync_conflicts')->insert([
            'id' => (string) Str::uuid(), 'school_id' => $user->school_id,
            'branch_id' => $serverRow['branch_id'] ?? $data['branch_id'] ?? null, 'table_name' => $table, 'row_id' => $id,
            'base_version' => $base, 'server_version' => $serverVersion,
            'client_changes' => json_encode($data), 'server_row' => json_encode($serverRow),
            'device_id' => $device->id, 'user_id' => $user->id, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Fields changed on the server after the client's base version, from the audit log. */
    private function changedSince(string $table, string $id, int $baseVersion): array
    {
        $fields = [];
        DB::table('audit_logs')->where('table_name', $table)->where('row_id', $id)->where('action', 'update')
            ->orderBy('id')->get(['after'])->each(function ($log) use (&$fields, $baseVersion) {
                $after = json_decode($log->after, true) ?? [];
                if (($after['version'] ?? 0) > $baseVersion) {
                    $fields = array_merge($fields, array_keys($after));
                }
            });
        return array_values(array_diff(array_unique($fields), ['version', 'updated_by', 'updated_at']));
    }

    private function audit(User $user, Device $device, string $action, string $table, string $id, ?array $before, ?array $after): void
    {
        DB::table('audit_logs')->insert([
            'school_id' => $user->school_id, 'user_id' => $user->id, 'device_id' => $device->id,
            'action' => $action, 'table_name' => $table, 'row_id' => $id,
            'before' => $before ? json_encode($before) : null, 'after' => $after ? json_encode($after) : null,
            'ip' => request()?->ip(), 'created_at' => now(),
        ]);
    }
}
