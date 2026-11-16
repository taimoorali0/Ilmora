<?php

namespace Database\Seeders;

use App\Services\Finance\PaymentRecorder;
use App\Services\Finance\VoucherRun;
use App\Services\Platform\SchoolProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Full demo: the same data the demo app shows (apps/mobile `npm run demo:export`).
 * One busy school (code "demo"), one empty school and the super admin.
 *
 *   php artisan migrate:fresh --seed --seeder=DemoSeeder
 *
 * Logins, password "password": owner 03000000001, teacher 03000000002, principal 03000000003,
 * accountant 03000000004, admin 03000000005, examiner 03000000006, parent 03000000007.
 * Super admin: admin@ilmora.test.
 */
class DemoSeeder extends Seeder
{
    private const FIRST = ['plans', 'schools', 'branches', 'subscriptions', 'users', 'user_roles'];

    public function run(PaymentRecorder $payments, VoucherRun $vouchers): void
    {
        $data = json_decode(gzdecode(file_get_contents(__DIR__.'/demo-data.json.gz')), true);
        $password = Hash::make('password');
        $tables = array_unique(array_merge(self::FIRST, array_keys($data)));

        foreach ($tables as $table) {
            $columns = array_flip(Schema::getColumnListing($table));
            $rows = [];
            foreach ($data[$table] ?? [] as $row) {
                $row = array_intersect_key($row, $columns);
                foreach ($row as $col => $value) {
                    if (is_array($value)) {
                        $row[$col] = json_encode($value);
                    } elseif (is_string($value) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:/', $value)) {
                        $row[$col] = Carbon::parse($value)->utc()->format('Y-m-d H:i:s'); // ISO from the app → database timestamp
                    }
                }
                if (isset($columns['version'])) {
                    $row['version'] = max(1, (int) ($row['version'] ?? 1));
                }
                if ($table === 'users') {
                    $row['password'] = $password;
                }
                $rows[] = $row;
            }
            foreach (array_chunk($rows, 400) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }

        // Books: chart of accounts, a journal per voucher, then each payment in the order it was received.
        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            foreach (SchoolProvisioner::ACCOUNTS as [$code, $name, $type]) {
                DB::table('accounts')->insert(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'code' => $code, 'name' => $name,
                    'type' => $type, 'owner_type' => 'school', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $lines = DB::table('invoice_lines')->get()->groupBy('invoice_id');
        foreach (DB::table('invoices')->orderBy('period')->get() as $invoice) {
            $vouchers->postJournal($invoice->school_id, $invoice->branch_id, $invoice->family_id, $invoice->id,
                ($lines[$invoice->id] ?? collect())->map(fn ($l) => (array) $l)->all(), null);
        }
        foreach (DB::table('payments')->orderBy('received_at')->pluck('id') as $paymentId) {
            $payments->afterInsert($paymentId);
        }
        DB::table('reconciliation_items')->delete(); // seeded history has nothing to reconcile
        DB::table('payments')->update(['sync_state' => 'accepted']);
    }
}
