<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * First-time setup of a production server: the plans and the platform super admin.
 * Schools are then created from the platform console (docs/decisions.md D10).
 *
 *   php artisan ilmora:install --email=you@example.com --password='a-long-password'
 */
class Install extends Command
{
    protected $signature = 'ilmora:install {--email= : Super admin email} {--password= : Super admin password (min 10 characters)} {--name=Ilmora Super Admin}';

    protected $description = 'Create the subscription plans and the platform super admin';

    public function handle(): int
    {
        $email = strtolower((string) ($this->option('email') ?: $this->ask('Super admin email')));
        $password = (string) ($this->option('password') ?: $this->secret('Super admin password (min 10 characters)'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
            $this->error('Give a valid email and a password of at least 10 characters.');

            return self::FAILURE;
        }
        $now = now();
        if (! DB::table('plans')->exists()) {
            foreach ([['Basic', 300, 30, 1, 5000], ['Standard', 1000, 100, 2, 12000], ['Premium', null, null, 5, 25000]] as [$name, $students, $staff, $branches, $price]) {
                DB::table('plans')->insert(['id' => (string) Str::uuid(), 'name' => $name, 'max_students' => $students, 'max_staff' => $staff,
                    'max_branches' => $branches, 'monthly_message_credits' => 2000, 'modules' => json_encode(config('ilmora.modules')),
                    'price_monthly' => $price, 'price_yearly' => $price * 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            $this->info('Plans created: Basic, Standard, Premium. Change prices in the admin panel at /admin.');
        }
        $existing = DB::table('users')->whereNull('school_id')->where('email', $email)->first();
        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update(['password' => Hash::make($password), 'is_super_admin' => true, 'status' => 'active', 'updated_at' => $now]);
            $this->info("Password updated for $email.");
        } else {
            DB::table('users')->insert(['id' => (string) Str::uuid(), 'school_id' => null, 'name' => $this->option('name'), 'email' => $email,
                'password' => Hash::make($password), 'is_super_admin' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            $this->info("Super admin created: $email.");
        }

        return self::SUCCESS;
    }
}
