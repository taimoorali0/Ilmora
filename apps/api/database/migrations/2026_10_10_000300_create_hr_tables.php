<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Staff, shifts, leave, biometric punches, attendance days, payroll.
 * Punch processing rules: docs/decisions.md D8. Payroll approval: D5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->syncable();
            $table->string('employee_no');
            $table->string('name');
            $table->string('cnic', 15)->nullable();
            $table->string('phone')->nullable();
            $table->string('designation');
            $table->date('joining_date');
            $table->uuid('shift_id')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('device_user_id')->nullable(); // id enrolled on the biometric device
            $table->enum('status', ['active', 'on_notice', 'left'])->default('active');
            $table->unique(['school_id', 'employee_no']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->syncable();
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at');               // may be earlier than starts_at = overnight
            $table->unsignedSmallInteger('grace_minutes')->default(10);
            $table->unsignedSmallInteger('half_day_after_minutes')->default(90);
            $table->unsignedSmallInteger('early_leave_grace_minutes')->default(10);
            $table->unsignedSmallInteger('window_before_minutes')->default(120); // punch window opens
            $table->unsignedSmallInteger('window_after_minutes')->default(240);  // closes after end
            $table->json('weekly_offs');           // [0] = Sunday
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->syncable();
            $table->date('date');
            $table->string('name');
            $table->boolean('for_staff')->default(true);
            $table->boolean('for_students')->default(true);
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->syncable(branchScoped: false);
            $table->string('name');                // Casual, Sick, Earned, Unpaid
            $table->unsignedSmallInteger('yearly_quota')->default(0);
            $table->boolean('paid')->default(true);
        });

        Schema::create('staff_leaves', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('staff_id')->index();
            $table->uuid('leave_type_id');
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('half_day')->default(false);
            $table->string('reason')->nullable();
            $table->enum('status', ['requested', 'approved', 'rejected', 'cancelled'])->default('requested');
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
        });

        Schema::create('bio_devices', function (Blueprint $table) {
            $table->syncable();
            $table->string('serial')->unique();
            $table->string('model')->nullable();
            $table->enum('method', ['adms_push', 'lan_bridge', 'file_import']);
            $table->string('ip')->nullable();
            $table->timestamp('last_contact_at')->nullable();
        });

        // Raw punches exactly as the device reported them. Never edited (D8).
        Schema::create('bio_punches', function (Blueprint $table) {
            $table->appendOnly();
            $table->uuid('bio_device_id')->index();
            $table->string('device_user_id');
            $table->timestamp('punched_at');
            $table->string('verify_mode')->nullable(); // finger, face, card
            $table->json('raw')->nullable();
            $table->unique(['bio_device_id', 'device_user_id', 'punched_at']); // device re-sends are ignored
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('staff_id')->index();
            $table->date('shift_date');
            $table->timestamp('check_in')->nullable();
            $table->timestamp('check_out')->nullable();
            $table->string('reason');
            $table->enum('status', ['requested', 'approved', 'rejected'])->default('requested');
            $table->uuid('decided_by')->nullable();
        });

        // Computed by the server from punches + corrections + leave + holidays.
        Schema::create('staff_attendance_days', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('staff_id');
            $table->date('shift_date');            // the date the shift STARTS (overnight safe)
            $table->timestamp('check_in')->nullable();
            $table->timestamp('check_out')->nullable();
            $table->enum('status', ['present', 'late', 'half_day', 'absent', 'incomplete',
                'leave_paid', 'leave_unpaid', 'holiday', 'weekly_off']);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);
            $table->unsignedSmallInteger('punch_count')->default(0);
            $table->boolean('locked')->default(false); // locked for payroll month
            $table->unique(['staff_id', 'shift_date']);
        });

        // ---------- Payroll ----------
        Schema::create('salary_components', function (Blueprint $table) {
            $table->syncable(branchScoped: false);
            $table->string('name');
            $table->enum('type', ['earning', 'deduction']);
            $table->enum('calc', ['fixed', 'percent_of_basic', 'per_entry', 'rule']);
            $table->string('rule_key')->nullable(); // absent, late, half_day, unpaid_leave, loan, tax, eobi
        });

        Schema::create('salary_structures', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('staff_id')->index();
            $table->uuid('salary_component_id');
            $table->unsignedInteger('amount')->nullable();
            $table->decimal('percent', 5, 2)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
        });

        Schema::create('staff_loans', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('staff_id')->index();
            $table->unsignedInteger('amount');
            $table->unsignedInteger('installment');
            $table->string('starts_period', 7);    // 2026-11
            $table->enum('status', ['requested', 'active', 'closed', 'rejected'])->default('requested');
            $table->uuid('approved_by')->nullable();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->syncable();
            $table->string('period', 7);
            $table->enum('status', ['draft', 'reviewed', 'approved', 'paid'])->default('draft');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unique(['branch_id', 'period']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('payroll_run_id')->index();
            $table->uuid('staff_id')->index();
            $table->unsignedInteger('gross');
            $table->unsignedInteger('total_deductions');
            $table->integer('net');
            $table->json('lines');                 // [{component, type, amount, basis}]
            $table->json('attendance_summary');    // {present, late, half_day, absent, leave...}
            $table->enum('payment_method', ['bank', 'cash', 'cheque'])->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unique(['payroll_run_id', 'staff_id']);
        });
    }

    public function down(): void
    {
        foreach (['payslips', 'payroll_runs', 'staff_loans', 'salary_structures', 'salary_components',
            'staff_attendance_days', 'attendance_corrections', 'bio_punches', 'bio_devices', 'staff_leaves',
            'leave_types', 'holidays', 'shifts', 'staff'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
