<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Fees and money (docs/decisions.md D3, D4).
 * Amounts are whole rupees (integers; PKR fees have no subunit in practice).
 * Never use floats for money.
 *
 * Invariants:
 *  - payments, payment_allocations, reversals, ledger_entries are append-only.
 *  - A family's balance = SUM(ledger_entries.debit - credit) for its account.
 *  - Client retries reuse the same UUID, so inserts are idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Configuration (protected, D2) ----------
        Schema::create('fee_heads', function (Blueprint $table) {
            $table->syncable(branchScoped: false);
            $table->string('name');                // Tuition, Admission, Annual, Transport
            $table->enum('frequency', ['one_time', 'monthly', 'yearly', 'per_term', 'on_demand']);
            $table->boolean('discountable')->default(true);
            $table->boolean('active')->default(true);
        });

        Schema::create('fee_structures', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('academic_session_id');
            $table->uuid('class_id')->index();
            $table->uuid('fee_head_id');
            $table->unsignedInteger('amount');
            $table->unsignedTinyInteger('due_day')->default(10);
            $table->json('months')->nullable();    // [4,5,...] for which months a monthly head applies
            $table->unique(['academic_session_id', 'class_id', 'fee_head_id']);
        });

        // Sibling, scholarship, staff-child, special. Applied in priority order.
        Schema::create('discount_rules', function (Blueprint $table) {
            $table->syncable(branchScoped: false);
            $table->string('name');
            $table->enum('kind', ['sibling', 'scholarship', 'staff_child', 'special']);
            $table->unsignedTinyInteger('priority');
            $table->json('config');                // sibling: {"2":10,"3":15,"4+":20}
            $table->unsignedTinyInteger('max_total_percent')->default(50);
            $table->boolean('active')->default(true);
        });

        Schema::create('student_discounts', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('student_id')->index();
            $table->uuid('discount_rule_id');
            $table->unsignedTinyInteger('percent')->nullable();
            $table->unsignedInteger('fixed_amount')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->string('reason')->nullable();
        });

        Schema::create('fine_rules', function (Blueprint $table) {
            $table->syncable(branchScoped: false);
            $table->enum('kind', ['fixed', 'per_day']);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('cap')->nullable();
            $table->unsignedTinyInteger('grace_days')->default(0);
            $table->boolean('active')->default(true);
        });

        // ---------- Invoices ----------
        // Created by the server-side voucher run (Finalize level, D1).
        Schema::create('invoices', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('family_id')->index();
            $table->string('number');              // INV-B1-2026-10-000123
            $table->string('period', 7);           // 2026-10
            $table->date('issue_date');
            $table->date('due_date');
            $table->enum('status', ['issued', 'partially_paid', 'paid', 'cancelled'])->default('issued');
            $table->string('challan_ref')->nullable(); // bank / 1Link / wallet reference
            $table->unique(['school_id', 'number']);
            $table->unique(['family_id', 'period']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->syncable();
            $table->uuid('invoice_id')->index();
            $table->uuid('student_id')->nullable()->index();
            $table->uuid('fee_head_id')->nullable();
            $table->enum('kind', ['fee', 'discount', 'fine', 'arrears_note', 'adjustment']);
            $table->string('description');
            $table->integer('amount');             // discounts are negative
            $table->uuid('discount_rule_id')->nullable();
        });

        // ---------- Payments (append-only) ----------
        Schema::create('payments', function (Blueprint $table) {
            $table->appendOnly();
            $table->uuid('family_id')->index();
            $table->string('receipt_no');          // B1-D07-000123 (D4)
            $table->unsignedInteger('amount');
            $table->enum('method', ['cash', 'bank', 'jazzcash', 'easypaisa', 'cheque', 'card', 'adjustment']);
            $table->string('reference')->nullable();
            $table->timestamp('received_at');      // device time when money was taken
            $table->uuid('received_by');
            $table->enum('sync_state', ['accepted', 'pending_reconciliation'])->default('accepted');
            $table->unique(['school_id', 'receipt_no']);
        });

        // Which invoice lines a payment settles. Oldest arrears first, then fines, then current.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->appendOnly();
            $table->uuid('payment_id')->index();
            $table->uuid('invoice_id')->index();
            $table->uuid('invoice_line_id')->nullable();
            $table->unsignedInteger('amount');
        });

        // Cancelling a payment / invoice line / allocation. Never delete the original.
        Schema::create('reversals', function (Blueprint $table) {
            $table->appendOnly();
            $table->enum('target_type', ['payment', 'invoice', 'invoice_line', 'payment_allocation']);
            $table->uuid('target_id')->index();
            $table->string('reason');
            $table->uuid('approved_by')->nullable();
        });

        // ---------- Ledger ----------
        Schema::create('accounts', function (Blueprint $table) {
            $table->syncable(branchScoped: false);
            $table->string('code');                // 1100, 4100 ...
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'income', 'expense', 'equity']);
            $table->enum('owner_type', ['school', 'family', 'staff'])->default('school');
            $table->uuid('owner_id')->nullable()->index(); // family_id for receivables
            $table->unique(['school_id', 'code']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->appendOnly();
            $table->uuid('journal_id')->index();   // groups balanced debit/credit lines
            $table->uuid('account_id')->index();
            $table->unsignedInteger('debit')->default(0);
            $table->unsignedInteger('credit')->default(0);
            $table->date('entry_date');
            $table->string('source_type');         // invoice, payment, reversal, expense, payroll
            $table->uuid('source_id')->index();
            $table->string('memo')->nullable();
        });

        // Items needing a human: overpayment from two offline devices, unmatched bank credit...
        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->syncable();
            $table->enum('kind', ['overpayment', 'duplicate_suspect', 'unmatched_bank', 'amount_mismatch']);
            $table->uuid('family_id')->nullable()->index();
            $table->json('refs');                  // payment ids, invoice ids
            $table->integer('amount')->default(0);
            $table->enum('status', ['open', 'resolved', 'ignored'])->default('open');
            $table->uuid('resolved_by')->nullable();
            $table->text('resolution')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['reconciliation_items', 'ledger_entries', 'accounts', 'reversals', 'payment_allocations',
            'payments', 'invoice_lines', 'invoices', 'fine_rules', 'student_discounts', 'discount_rules',
            'fee_structures', 'fee_heads'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
