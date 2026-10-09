<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Development data: one super admin, one demo school with one branch,
 * an owner and a teacher, and the base chart of accounts.
 * Logins (school slug "demo"): owner 03000000001 / password, teacher 03000000002 / password
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $uuid = fn () => (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $uuid(), 'school_id' => null, 'name' => 'Ilmora Super Admin', 'email' => 'admin@ilmora.test',
            'password' => Hash::make('password'), 'is_super_admin' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $planId = $uuid();
        DB::table('plans')->insert([
            'id' => $planId, 'name' => 'Standard', 'max_students' => 1000, 'max_staff' => 100, 'max_branches' => 2,
            'monthly_message_credits' => 2000, 'modules' => json_encode(config('ilmora.modules')),
            'price_monthly' => 0, 'price_yearly' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $school = $uuid();
        DB::table('schools')->insert([
            'id' => $school, 'name' => 'Demo Public School', 'slug' => 'demo', 'phone' => '0300-0000000',
            'address' => 'Lahore', 'settings' => json_encode(['payroll' => ['day_basis' => 'working_days', 'lates_per_day' => 3]]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('subscriptions')->insert([
            'id' => $uuid(), 'school_id' => $school, 'plan_id' => $planId, 'starts_on' => $now->toDateString(),
            'ends_on' => $now->copy()->addYear()->toDateString(), 'created_at' => $now, 'updated_at' => $now,
        ]);

        $branch = $uuid();
        DB::table('branches')->insert([
            'id' => $branch, 'school_id' => $school, 'name' => 'Main Campus', 'code' => 'B1',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ([['Owner', '03000000001', 'owner', null], ['Teacher', '03000000002', 'teacher', $branch]] as [$name, $phone, $role, $br]) {
            $uid = $uuid();
            DB::table('users')->insert([
                'id' => $uid, 'school_id' => $school, 'name' => "Demo $name", 'phone' => $phone,
                'password' => Hash::make('password'), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('user_roles')->insert([
                'id' => $uuid(), 'school_id' => $school, 'user_id' => $uid, 'role' => $role, 'branch_id' => $br,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach ([['1100', 'Cash in hand', 'asset'], ['1200', 'Bank', 'asset'], ['2100', 'Advance fee / family credit', 'liability'],
            ['4100', 'Tuition fee income', 'income'], ['4200', 'Fine income', 'income'], ['5100', 'Discounts given', 'expense'],
            ['5200', 'Salaries', 'expense']] as [$code, $name, $type]) {
            DB::table('accounts')->insert([
                'id' => $uuid(), 'school_id' => $school, 'code' => $code, 'name' => $name, 'type' => $type,
                'owner_type' => 'school', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
