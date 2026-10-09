<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Platform + tenancy (docs/decisions.md D9, D10).
 * Only the super admin creates plans, schools and the first owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->unsignedInteger('max_students')->nullable();
            $table->unsignedInteger('max_staff')->nullable();
            $table->unsignedInteger('max_branches')->default(1);
            $table->unsignedInteger('monthly_message_credits')->default(0);
            $table->json('modules');               // module keys included in the plan
            $table->unsignedInteger('price_monthly')->default(0); // PKR
            $table->unsignedInteger('price_yearly')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('timezone')->default('Asia/Karachi');
            $table->string('currency', 3)->default('PKR');
            $table->enum('status', ['active', 'suspended', 'read_only', 'closed'])->default('active');
            $table->unsignedSmallInteger('offline_access_days')->default(7); // D6
            $table->json('settings')->nullable();  // late rules, fine rules, payroll basis...
            $table->uuid('created_by')->nullable(); // super admin
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->string('name');
            $table->string('code', 8);              // used in receipt numbers: B1
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['school_id', 'code']);
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('plan_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('grace_days')->default(7);
            $table->unsignedInteger('amount')->default(0);
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign('school_id')->references('id')->on('schools')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans');
        });

        // Per-school overrides on top of the plan (super admin switch).
        Schema::create('school_modules', function (Blueprint $table) {
            $table->uuid('school_id');
            $table->string('module');
            $table->boolean('enabled');
            $table->timestamps();
            $table->primary(['school_id', 'module']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->nullable()->index(); // null only for super admins
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('password');
            $table->boolean('is_super_admin')->default(false);
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->uuid('staff_id')->nullable();   // link to staff record
            $table->uuid('family_id')->nullable();  // link for parent accounts
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['school_id', 'phone']);
            $table->unique(['school_id', 'email']);
        });

        /*
         * Role assignments. A role is scoped to a branch, or the whole school
         * when branch_id is null. Abilities per role live in config/ilmora.php.
         */
        Schema::create('user_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('user_id')->index();
            $table->enum('role', ['owner', 'principal', 'admin', 'accountant', 'examiner', 'teacher', 'parent']);
            $table->uuid('branch_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'role', 'branch_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Registered devices: offline grant (D6) and receipt prefix (D4).
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('school_id')->index();
            $table->uuid('branch_id')->nullable();
            $table->uuid('user_id')->index();
            $table->string('code', 8);              // D07
            $table->string('name')->nullable();
            $table->enum('platform', ['android', 'ios', 'web', 'desktop']);
            $table->timestamp('offline_valid_until');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('wipe_on_contact')->default(false);
            $table->timestamps();
            $table->unique(['school_id', 'code']);
        });

        Schema::create('device_sequences', function (Blueprint $table) {
            $table->uuid('device_id');
            $table->string('kind');                 // receipt, voucher...
            $table->unsignedInteger('last_value')->default(0);
            $table->primary(['device_id', 'kind']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        foreach (['sessions', 'password_reset_tokens', 'device_sequences', 'devices', 'user_roles',
            'users', 'school_modules', 'subscriptions', 'branches', 'schools', 'plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
