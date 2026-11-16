<?php

/*
 * Ilmora domain configuration.
 */
return [

    'offline_access_days' => (int) env('OFFLINE_ACCESS_DAYS', 7),

    // API tokens expire; the app signs in again online after this many days.
    'token_days' => (int) env('API_TOKEN_DAYS', 30),

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
        'principal' => ['setup.*', 'students.*', 'families.*', 'attendance.*', 'fees.view', 'fees.approve',
            'exams.*', 'exam_papers.*', 'syllabus.*', 'worksheets.*', 'staff.*', 'staff_attendance.*',
            'staff_leaves.*', 'payroll.view', 'payroll.approve', 'messaging.*', 'reports.*', 'users.manage',
            'expenses.view', 'sync_conflicts.resolve'],
        'admin' => ['setup.*', 'students.*', 'families.*', 'attendance.*', 'staff.view', 'staff.edit',
            'staff_attendance.*', 'messaging.*', 'worksheets.*', 'staff_leaves.request', 'payslips.own'],
        'accountant' => ['students.view', 'families.view', 'fees.*', 'payments.*', 'expenses.*',
            'payroll.view', 'payroll.edit', 'reconciliation.*', 'messaging.send', 'staff_leaves.request', 'payslips.own'],
        'examiner' => ['exams.view', 'exams.edit', 'exams.marks_entry', 'exam_papers.write', 'exam_papers.review',
            'staff_leaves.request', 'payslips.own'],
        // Teachers message the parents of their own classes (absence, homework, results).
        'teacher' => ['students.view', 'attendance.mark', 'exams.marks_entry', 'exam_papers.write',
            'syllabus.*', 'worksheets.*', 'staff_leaves.request', 'payslips.own', 'messaging.send'],
        'parent' => ['children.view', 'invoices.own', 'payments.online', 'leave_requests.create'],
    ],

    /*
     * How the sync upload endpoint treats client writes per table (docs/decisions.md D2).
     *  mergeable: version must match, else non-overlapping fields merge, else conflict
     *  protected: version must match, else conflict row; never auto-merged
     *  append:    insert only; same id again = ignored (idempotent retry)
     *  server:    clients may not write; server computes these
     * `ability` may be a list: holding any one of them is enough.
     * Tables not listed are rejected.
     */
    'sync_tables' => [
        // setup
        'academic_sessions' => ['mode' => 'protected', 'ability' => 'setup.edit'],
        'classes' => ['mode' => 'protected', 'ability' => 'setup.edit'],
        'sections' => ['mode' => 'protected', 'ability' => 'setup.edit'],
        'subjects' => ['mode' => 'protected', 'ability' => 'setup.edit'],
        'section_subjects' => ['mode' => 'protected', 'ability' => 'setup.edit'],
        'holidays' => ['mode' => 'protected', 'ability' => 'setup.edit'],
        // students
        'families' => ['mode' => 'mergeable', 'ability' => 'families.edit'],
        'guardians' => ['mode' => 'mergeable', 'ability' => 'families.edit'],
        'students' => ['mode' => 'mergeable', 'ability' => 'students.edit'],
        'enrollments' => ['mode' => 'protected', 'ability' => 'students.edit'],
        'student_attendance' => ['mode' => 'mergeable', 'ability' => 'attendance.mark'],
        // learning
        'exams' => ['mode' => 'mergeable', 'ability' => 'exams.edit'],
        'exam_subjects' => ['mode' => 'mergeable', 'ability' => 'exams.edit'],
        'marks' => ['mode' => 'mergeable', 'ability' => 'exams.marks_entry'],
        'exam_papers' => ['mode' => 'mergeable',
            'ability' => ['exam_papers.write', 'exam_papers.review', 'exam_papers.approve_final']],
        'syllabus_topics' => ['mode' => 'mergeable', 'ability' => 'syllabus.edit'],
        'homework' => ['mode' => 'mergeable', 'ability' => 'worksheets.edit'],
        'notices' => ['mode' => 'mergeable', 'ability' => 'messaging.send'],
        'messages' => ['mode' => 'mergeable', 'ability' => 'messaging.send'],
        // fees
        'fee_heads' => ['mode' => 'protected', 'ability' => 'fees.edit'],
        'fee_structures' => ['mode' => 'protected', 'ability' => 'fees.edit'],
        'discount_rules' => ['mode' => 'protected', 'ability' => 'fees.edit'],
        'student_discounts' => ['mode' => 'protected', 'ability' => 'fees.edit'],
        'fine_rules' => ['mode' => 'protected', 'ability' => 'fees.edit'],
        'payments' => ['mode' => 'append', 'ability' => 'payments.collect'],
        'reversals' => ['mode' => 'append', 'ability' => 'payments.reverse'],
        'expenses' => ['mode' => 'mergeable', 'ability' => 'expenses.edit'],
        'invoices' => ['mode' => 'server'],
        'invoice_lines' => ['mode' => 'server'],
        'payment_allocations' => ['mode' => 'server'],
        'ledger_entries' => ['mode' => 'server'],
        // staff
        'staff' => ['mode' => 'mergeable', 'ability' => 'staff.edit'],
        'shifts' => ['mode' => 'protected', 'ability' => 'staff.edit'],
        'leave_types' => ['mode' => 'protected', 'ability' => 'staff.edit'],
        'staff_leaves' => ['mode' => 'mergeable', 'ability' => 'staff_leaves.request'],
        'attendance_corrections' => ['mode' => 'mergeable', 'ability' => 'staff_leaves.request'],
        // Manual marking for schools without a biometric device; punches still win on the server.
        'staff_attendance_days' => ['mode' => 'mergeable', 'ability' => 'staff_attendance.mark'],
        'salary_structures' => ['mode' => 'protected', 'ability' => 'payroll.edit'],
        'staff_loans' => ['mode' => 'mergeable', 'ability' => 'payroll.edit'],
        'payroll_runs' => ['mode' => 'server'],
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
        'staff_attendance_days' => ['locked'],
        'messages' => ['status', 'sent_at', 'error'],
        'staff_loans' => ['status', 'approved_by'],
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
        'exam_id' => 'exams',
        'exam_subject_id' => 'exam_subjects',
        'shift_id' => 'shifts',
        'class_teacher_id' => 'staff',
        'teacher_id' => 'staff',
        'author_id' => 'users',
    ],

    // Polymorphic targets for reversals.target_type → table.
    /*
     * Parent messages. Driver "log" writes to the Laravel log (development);
     * "http" posts JSON to MESSAGE_HTTP_URL (any WhatsApp Business / SMS gateway).
     */
    'messaging' => [
        'driver' => env('MESSAGE_DRIVER', 'log'),
        'http_url' => env('MESSAGE_HTTP_URL'),
        'http_token' => env('MESSAGE_HTTP_TOKEN'),
    ],

    'reversal_targets' => [
        'payment' => 'payments',
        'invoice' => 'invoices',
        'invoice_line' => 'invoice_lines',
        'payment_allocation' => 'payment_allocations',
    ],
];
