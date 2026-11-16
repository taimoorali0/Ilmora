<?php

namespace App\Services\Platform;

use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a school with everything it needs to sign in and start work (D10):
 * first campus, owner login, subscription, chart of accounts, academic session,
 * classes, fee heads, leave types and a default shift.
 */
final class SchoolProvisioner
{
    public const ACCOUNTS = [
        ['1100', 'Cash in hand', 'asset'], ['1200', 'Bank', 'asset'], ['2100', 'Advance fee / family credit', 'liability'],
        ['4100', 'Tuition fee income', 'income'], ['4200', 'Fine income', 'income'], ['5100', 'Discounts given', 'expense'],
        ['5200', 'Salaries', 'expense'],
    ];

    public const SUBJECTS = ['English', 'Urdu', 'Mathematics', 'Science', 'Islamiyat'];

    /** @param array{name:string, slug:string, owner_name:string, owner_phone:string, owner_password:string, plan_id:string,
     *   phone?:?string, email?:?string, address?:?string, branch_name?:?string, ends_on?:?string} $data */
    public function create(array $data, ?string $createdBy = null): School
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $now = now();
            $uuid = fn () => (string) Str::uuid();
            $school = School::create([
                'name' => $data['name'], 'slug' => Str::slug($data['slug']), 'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null, 'status' => 'active',
                'settings' => ['payroll' => ['day_basis' => 'working_days', 'lates_per_day' => 3]], 'created_by' => $createdBy,
            ]);
            $stamp = ['created_at' => $now, 'updated_at' => $now];
            $tenant = ['school_id' => $school->id, 'version' => 1] + $stamp;
            $branchId = $uuid();
            DB::table('branches')->insert(['id' => $branchId, 'school_id' => $school->id,
                'name' => $data['branch_name'] ?? 'Main Campus', 'code' => 'B1'] + $stamp);
            $ownerId = $uuid();
            DB::table('users')->insert(['id' => $ownerId, 'school_id' => $school->id, 'name' => $data['owner_name'],
                'phone' => $data['owner_phone'], 'password' => Hash::make($data['owner_password']), 'status' => 'active'] + $stamp);
            DB::table('user_roles')->insert(['id' => $uuid(), 'school_id' => $school->id, 'user_id' => $ownerId,
                'role' => 'owner', 'branch_id' => null] + $stamp);
            DB::table('subscriptions')->insert(['id' => $uuid(), 'school_id' => $school->id, 'plan_id' => $data['plan_id'],
                'starts_on' => $now->toDateString(), 'ends_on' => $data['ends_on'] ?? $now->copy()->addYear()->toDateString(),
                'status' => 'active'] + $stamp);
            foreach (self::ACCOUNTS as [$code, $name, $type]) {
                DB::table('accounts')->insert(['id' => $uuid(), 'code' => $code, 'name' => $name, 'type' => $type, 'owner_type' => 'school'] + $tenant);
            }

            // Starter setup so the owner is not staring at an empty app.
            $year = $now->month >= 4 ? $now->year : $now->year - 1;
            DB::table('academic_sessions')->insert(['id' => $uuid(), 'name' => $year.'-'.substr((string) ($year + 1), 2),
                'starts_on' => "$year-04-01", 'ends_on' => ($year + 1).'-03-31', 'is_current' => true, 'status' => 'open'] + $tenant);
            $sessionId = DB::table('academic_sessions')->where('school_id', $school->id)->value('id');
            $subjectIds = [];
            foreach (self::SUBJECTS as $subject) {
                DB::table('subjects')->insert(['id' => $subjectIds[] = $uuid(), 'name' => $subject, 'code' => strtoupper(substr($subject, 0, 3))] + $tenant);
            }
            foreach (['Play Group', 'Nursery', 'Prep', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10'] as $i => $class) {
                DB::table('classes')->insert(['id' => $classId = $uuid(), 'branch_id' => $branchId, 'name' => $class, 'sort_order' => $i + 1] + $tenant);
                // One section and the core subjects per class, so admissions and exams work on day one.
                DB::table('sections')->insert(['id' => $sectionId = $uuid(), 'branch_id' => $branchId, 'class_id' => $classId,
                    'academic_session_id' => $sessionId, 'name' => 'A', 'capacity' => 30] + $tenant);
                foreach ($subjectIds as $subjectId) {
                    DB::table('section_subjects')->insert(['id' => $uuid(), 'branch_id' => $branchId, 'section_id' => $sectionId, 'subject_id' => $subjectId] + $tenant);
                }
            }
            foreach ([['Tuition fee', 'monthly'], ['Admission fee', 'one_time'], ['Annual charges', 'yearly'], ['Exam fee', 'per_term']] as [$head, $freq]) {
                DB::table('fee_heads')->insert(['id' => $uuid(), 'name' => $head, 'frequency' => $freq, 'discountable' => $head === 'Tuition fee'] + $tenant);
            }
            DB::table('discount_rules')->insert(['id' => $uuid(), 'name' => 'Sibling discount', 'kind' => 'sibling', 'priority' => 1,
                'config' => json_encode(['2' => 10, '3' => 15, '4+' => 20])] + $tenant);
            DB::table('fine_rules')->insert(['id' => $uuid(), 'kind' => 'fixed', 'amount' => 200, 'grace_days' => 0] + $tenant);
            foreach ([['Casual', 10, true], ['Sick', 8, true], ['Unpaid', 0, false]] as [$leave, $quota, $paid]) {
                DB::table('leave_types')->insert(['id' => $uuid(), 'name' => $leave, 'yearly_quota' => $quota, 'paid' => $paid] + $tenant);
            }
            DB::table('shifts')->insert(['id' => $uuid(), 'branch_id' => $branchId, 'name' => 'Morning', 'starts_at' => '07:45',
                'ends_at' => '14:00', 'weekly_offs' => json_encode([0])] + $tenant);

            DB::table('audit_logs')->insert(['school_id' => $school->id, 'user_id' => $createdBy, 'action' => 'create_school',
                'table_name' => 'schools', 'row_id' => $school->id,
                'after' => json_encode(['name' => $school->name, 'slug' => $school->slug, 'owner_phone' => $data['owner_phone']]),
                'created_at' => $now]);

            return $school;
        });
    }
}
