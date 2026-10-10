<?php

/*
 * Ilmora domain configuration.
 */
return [

    'offline_access_days' => (int) env('OFFLINE_ACCESS_DAYS', 7),

    // API tokens expire; the app signs in again online after this many days.
    'token_days' => (int) env('API_TOKEN_DAYS', 30),

    'powersync' => [
        'url' => env('POWERSYNC_URL', 'http://localhost:8080'),
        'kid' => env('POWERSYNC_JWT_KID', 'ilmora-dev'),
        'secret' => env('POWERSYNC_JWT_SECRET'),   // base64url, shared with PowerSync service
        'ttl_minutes' => 60,
    ],

    'modules' => [
        'students', 'families', 'attendance', 'fees', 'exams', 'exam_papers', 'staff',
        'staff_attendance', 'payroll', 'timetable', 'syllabus', 'worksheets', 'messaging',
        'notices', 'expenses', 'certificates', 'transport', 'library', 'parent_app',
    ],

    /*
     * Abilities per role. Scope (own school / branch / class / children) is enforced
     * separately in policies and in PowerSync sync rules (docs/decisions.md D7).
     */
    'roles' => [
        'owner' => ['*'],
        'principal' => ['students.*', 'families.*', 'attendance.*', 'fees.view', 'fees.approve',
            'exams.*', 'exam_papers.approve_final', 'staff.*', 'staff_attendance.*', 'payroll.approve',
            'messaging.*', 'reports.*', 'sync_conflicts.resolve'],
        'admin' => ['students.*', 'families.*', 'attendance.view', 'staff.view', 'staff.edit', 'messaging.*'],
        'accountant' => ['students.view', 'families.view', 'fees.*', 'payments.*', 'expenses.*',
            'payroll.view', 'payroll.edit', 'reconciliation.*'],
        'examiner' => ['exams.view', 'exams.edit', 'exam_papers.review'],
        'teacher' => ['students.view', 'attendance.mark', 'exams.marks_entry', 'exam_papers.write',
            'syllabus.*', 'worksheets.*', 'staff_leaves.request', 'payslips.own'],
        'parent' => ['children.view', 'invoices.own', 'payments.online', 'leave_requests.create'],
    ],

    /*
     * How the sync upload endpoint treats client writes per table (docs/decisions.md D2).
     *  mergeable: version must match, else non-overlapping fields merge, else conflict
     *  protected: version must match, else conflict row; never auto-merged
     *  append:    insert only; same id again = ignored (idempotent retry)
     *  server:    clients may not write; server computes these
     * Tables not listed are rejected.
     */
    'sync_tables' => [
        'families' => ['mode' => 'mergeable', 'ability' => 'families.edit'],
        'guardians' => ['mode' => 'mergeable', 'ability' => 'families.edit'],
        'students' => ['mode' => 'mergeable', 'ability' => 'students.edit'],
        'enrollments' => ['mode' => 'protected', 'ability' => 'students.edit'],
        'student_attendance' => ['mode' => 'mergeable', 'ability' => 'attendance.mark'],
        'staff_leaves' => ['mode' => 'mergeable', 'ability' => 'staff_leaves.request'],
        'attendance_corrections' => ['mode' => 'mergeable', 'ability' => 'staff_leaves.request'],
        'fee_structures' => ['mode' => 'protected', 'ability' => 'fees.edit'],
        'student_discounts' => ['mode' => 'protected', 'ability' => 'fees.edit'],
        'salary_structures' => ['mode' => 'protected', 'ability' => 'payroll.edit'],
        'payments' => ['mode' => 'append', 'ability' => 'payments.collect'],
        'reversals' => ['mode' => 'append', 'ability' => 'payments.reverse'],
        'invoices' => ['mode' => 'server'],
        'invoice_lines' => ['mode' => 'server'],
        'payment_allocations' => ['mode' => 'server'],
        'ledger_entries' => ['mode' => 'server'],
        'staff_attendance_days' => ['mode' => 'server'],
        'payslips' => ['mode' => 'server'],
    ],

    /*
     * Columns a device may never set on a given table. Approvals, decisions and
     * server bookkeeping happen through server endpoints, never through sync upload.
     */
    'sync_locked_columns' => [
        'staff_leaves' => ['status', 'decided_by', 'decided_at'],
        'attendance_corrections' => ['status', 'decided_by'],
        'student_discounts' => ['approved_by'],
        'payments' => ['sync_state', 'received_by'],
        'reversals' => ['approved_by'],
    ],

    /*
     * Foreign-key columns a device may send, and the table each must point into.
     * Every referenced row must belong to the caller's school. Any other *_id
     * column in an upload is rejected.
     */
    'sync_references' => [
        'family_id' => 'families',
        'student_id' => 'students',
        'staff_id' => 'staff',
        'section_id' => 'sections',
        'class_id' => 'classes',
        'subject_id' => 'subjects',
        'academic_session_id' => 'academic_sessions',
        'fee_head_id' => 'fee_heads',
        'discount_rule_id' => 'discount_rules',
        'salary_component_id' => 'salary_components',
        'leave_type_id' => 'leave_types',
    ],

    // Polymorphic targets for reversals.target_type → table.
    'reversal_targets' => [
        'payment' => 'payments',
        'invoice' => 'invoices',
        'invoice_line' => 'invoice_lines',
        'payment_allocation' => 'payment_allocations',
    ],
];
