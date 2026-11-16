<?php

namespace App\Services\Sync;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sends a device the rows its user may hold (docs/decisions.md D7): a teacher gets
 * their own sections, a parent their own children, an accountant the finance data.
 * With `since` only rows changed after that moment are returned.
 */
final class Puller
{
    /** table => group */
    private const TABLES = [
        'academic_sessions' => 'core', 'classes' => 'core', 'sections' => 'core', 'subjects' => 'core',
        'section_subjects' => 'core', 'holidays' => 'core', 'notices' => 'core', 'exams' => 'core',
        'exam_subjects' => 'core', 'homework' => 'core', 'syllabus_topics' => 'core',
        'families' => 'people', 'guardians' => 'people', 'students' => 'people', 'enrollments' => 'people',
        'student_attendance' => 'people', 'marks' => 'people',
        'exam_papers' => 'papers',
        'fee_heads' => 'finance', 'fee_structures' => 'finance', 'discount_rules' => 'finance',
        'student_discounts' => 'finance', 'fine_rules' => 'finance', 'invoices' => 'finance',
        'invoice_lines' => 'finance', 'payments' => 'finance', 'reversals' => 'finance', 'expenses' => 'finance',
        'staff' => 'hr', 'shifts' => 'hr', 'leave_types' => 'hr', 'staff_leaves' => 'hr',
        'staff_attendance_days' => 'hr', 'staff_loans' => 'hr', 'payroll_runs' => 'hr', 'payslips' => 'hr',
        'messages' => 'messages',
        'users' => 'users', 'user_roles' => 'users',
    ];

    /** Whole-school access per role; teacher and parent are scoped row by row below. */
    private const GROUPS = [
        'owner' => ['core', 'people', 'papers', 'finance', 'hr', 'messages', 'users'],
        'principal' => ['core', 'people', 'papers', 'finance', 'hr', 'messages', 'users'],
        'accountant' => ['core', 'people', 'finance', 'hr', 'messages'],
        'admin' => ['core', 'people', 'messages'],
        'examiner' => ['core', 'people', 'papers'],
    ];

    private const APPEND_ONLY = ['payments', 'reversals'];

    private const HIDDEN = ['password', 'remember_token', 'bank_account', 'device_id'];

    /** @return array{cursor:string, tables:array<string, array<int, array<string,mixed>>>} */
    public function pull(User $user, ?string $since): array
    {
        $cursor = now()->subSeconds(2)->toIso8601String(); // small overlap; merges are idempotent
        $role = $this->strongestRole($user);
        $tables = [];
        foreach (self::TABLES as $table => $group) {
            $query = $this->scoped($user, $role, $table, $group);
            if (! $query) {
                continue;
            }
            if ($since) {
                $query->where($table.'.'.(in_array($table, self::APPEND_ONLY, true) ? 'created_at' : 'updated_at'), '>', Carbon::parse($since));
            } elseif ($table === 'student_attendance') {
                $query->where('date', '>=', now()->subDays(120)->toDateString());
            } elseif ($table === 'staff_attendance_days') {
                $query->where('shift_date', '>=', now()->subDays(120)->toDateString());
            }
            $rows = $query->select("$table.*")->limit(50000)->get();
            if ($rows->isNotEmpty() || ! $since) {
                $tables[$table] = $rows->map(fn ($r) => array_diff_key((array) $r, array_flip(self::HIDDEN)))->all();
            }
        }

        return ['cursor' => $cursor, 'tables' => $tables];
    }

    private function strongestRole(User $user): string
    {
        $roles = $user->roleKeys();
        foreach (['owner', 'principal', 'accountant', 'admin', 'examiner', 'teacher', 'parent'] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return 'none';
    }

    private function scoped(User $user, string $role, string $table, string $group): ?Builder
    {
        $q = DB::table($table)->where("$table.school_id", $user->school_id);
        if (in_array($group, self::GROUPS[$role] ?? [], true)) {
            return $q;
        }
        if ($role === 'admin' && $table === 'staff') {
            return $q;
        }
        // Office roles without HR access still see their own leave, attendance and payslips.
        if (in_array($role, ['admin', 'examiner'], true)) {
            $staffId = $user->staff_id ?? '00000000-0000-0000-0000-000000000000';

            return match ($table) {
                'staff' => $q->where('id', $staffId),
                'staff_leaves', 'staff_attendance_days', 'payslips' => $q->where('staff_id', $staffId),
                'leave_types', 'payroll_runs' => $q,
                default => null,
            };
        }
        if ($role === 'teacher') {
            return $this->teacher($user, $table, $group, $q);
        }
        if ($role === 'parent') {
            return $this->parent($user, $table, $q);
        }

        return null;
    }

    private function teacher(User $user, string $table, string $group, Builder $q): ?Builder
    {
        if ($group === 'core') {
            return $q;
        }
        $staffId = $user->staff_id ?? '00000000-0000-0000-0000-000000000000';
        $sections = fn () => DB::table('sections')->where('class_teacher_id', $staffId)->select('id')
            ->union(DB::table('section_subjects')->where('teacher_id', $staffId)->select('section_id'));
        $students = fn () => DB::table('enrollments')->whereIn('section_id', $sections())->where('status', 'active')->select('student_id');

        return match ($table) {
            'enrollments', 'student_attendance' => $q->whereIn('section_id', $sections()),
            'students' => $q->whereIn('id', $students()),
            'marks' => $q->whereIn('student_id', $students()),
            'families' => $q->whereIn('id', DB::table('students')->whereIn('id', $students())->select('family_id')),
            'exam_papers' => $q->where('author_id', $user->id),
            'staff' => $q->where('id', $staffId),
            'staff_leaves', 'staff_attendance_days', 'payslips' => $q->where('staff_id', $staffId),
            'leave_types', 'payroll_runs' => $q,
            default => null,
        };
    }

    private function parent(User $user, string $table, Builder $q): ?Builder
    {
        $familyId = $user->family_id ?? '00000000-0000-0000-0000-000000000000';
        $children = fn () => DB::table('students')->where('family_id', $familyId)->select('id');
        $sections = fn () => DB::table('enrollments')->whereIn('student_id', $children())->where('status', 'active')->select('section_id');

        return match ($table) {
            'academic_sessions', 'classes', 'sections', 'subjects', 'holidays', 'exam_subjects', 'fee_heads' => $q,
            'exams' => $q->where('status', 'published'),
            'notices' => $q->where('audience', '!=', 'staff'),
            'homework' => $q->whereIn('section_id', $sections()),
            'families' => $q->where('id', $familyId),
            'guardians', 'students', 'invoices', 'payments' => $q->where('family_id', $familyId),
            'enrollments', 'student_attendance' => $q->whereIn('student_id', $children()),
            'marks' => $q->whereIn('student_id', $children())
                ->whereIn('exam_subject_id', DB::table('exam_subjects')->whereIn('exam_id',
                    DB::table('exams')->where('status', 'published')->select('id'))->select('id')),
            'invoice_lines' => $q->whereIn('invoice_id', DB::table('invoices')->where('family_id', $familyId)->select('id')),
            default => null,
        };
    }
}
