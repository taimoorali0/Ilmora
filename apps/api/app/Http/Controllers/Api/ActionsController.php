<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Finance\VoucherRun;
use App\Services\Payroll\PayrollRun;
use App\Services\Sync\Puller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Server-only actions (Finalize level, docs/decisions.md D1): they need the full,
 * synchronized data set, so devices call them online and then pull the result.
 */
class ActionsController extends Controller
{
    public function pull(Request $request, Puller $puller)
    {
        $request->validate(['since' => 'nullable|date']);

        return $puller->pull($request->user(), $request->query('since'));
    }

    public function voucherRun(Request $request, VoucherRun $run)
    {
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'branch_id' => 'required|uuid']);
        $this->need($request, 'fees.edit', $data['branch_id']);

        return $this->domain(fn () => $run->run($request->user()->school_id, $data['branch_id'], $data['period'], $request->user()->id));
    }

    public function payrollDraft(Request $request, PayrollRun $run)
    {
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'branch_id' => 'required|uuid']);
        $this->need($request, 'payroll.edit', $data['branch_id']);

        return $this->domain(fn () => $run->draft($request->user()->school_id, $data['branch_id'], $data['period'], $request->user()->id));
    }

    public function payrollApprove(Request $request, string $run, PayrollRun $service)
    {
        $this->need($request, 'payroll.approve');

        return $this->domain(function () use ($request, $run, $service) {
            $service->approve($request->user()->school_id, $run, $request->user()->id);

            return ['ok' => true];
        });
    }

    public function payrollPay(Request $request, string $run, PayrollRun $service)
    {
        $this->need($request, 'payroll.approve');

        return $this->domain(function () use ($request, $run, $service) {
            $service->markPaid($request->user()->school_id, $run, $request->user()->id);

            return ['ok' => true];
        });
    }

    /** Approve or reject a staff leave request. */
    public function decideLeave(Request $request, string $leave)
    {
        $data = $request->validate(['status' => 'required|in:approved,rejected']);
        $this->need($request, 'staff.edit');
        $updated = DB::table('staff_leaves')->where('id', $leave)->where('school_id', $request->user()->school_id)
            ->where('status', 'requested')->update(['status' => $data['status'], 'decided_by' => $request->user()->id,
                'decided_at' => now(), 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        abort_unless($updated, 404, 'Leave request not found or already decided.');

        return ['ok' => true];
    }

    /** Create or update a login for a staff member or parent of this school. */
    public function saveUser(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $data = $request->validate([
            'id' => 'nullable|uuid',
            'name' => 'required|string|max:120',
            'phone' => ['required', 'string', 'max:20', Rule::unique('users')->where('school_id', $schoolId)->ignore($request->input('id'))],
            'password' => [$request->input('id') ? 'nullable' : 'required', 'string', 'min:6'],
            'role' => 'required|in:principal,admin,accountant,examiner,teacher,parent',
            'status' => 'nullable|in:active,disabled',
            'staff_id' => ['nullable', 'uuid', Rule::exists('staff', 'id')->where('school_id', $schoolId)],
            'family_id' => ['nullable', 'uuid', Rule::exists('families', 'id')->where('school_id', $schoolId)],
            'branch_id' => ['nullable', 'uuid', Rule::exists('branches', 'id')->where('school_id', $schoolId)],
        ]);
        $this->need($request, 'users.manage');

        return DB::transaction(function () use ($data, $schoolId) {
            $user = isset($data['id']) ? User::where('school_id', $schoolId)->findOrFail($data['id']) : new User(['school_id' => $schoolId]);
            abort_if($user->exists && $user->roles->contains('role', 'owner'), 403, 'The owner login is managed by Ilmora support.');
            $user->fill(['name' => $data['name'], 'phone' => $data['phone'], 'status' => $data['status'] ?? 'active',
                'staff_id' => $data['staff_id'] ?? null, 'family_id' => $data['family_id'] ?? null]);
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            DB::table('user_roles')->where('user_id', $user->id)->delete();
            DB::table('user_roles')->insert(['id' => (string) Str::uuid(), 'school_id' => $schoolId, 'user_id' => $user->id,
                'role' => $data['role'], 'branch_id' => $data['branch_id'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            if ($user->status !== 'active') {
                $user->tokens()->delete();
            }

            return ['id' => $user->id];
        });
    }

    private function need(Request $request, string $ability, ?string $branchId = null): void
    {
        abort_unless($request->user()->canDo($ability, $branchId), 403, 'Not permitted.');
        abort_unless($request->user()->school?->canWrite(), 403, 'School is not accepting changes (suspended or read-only).');
        if ($branchId) {
            abort_unless(DB::table('branches')->where('id', $branchId)->where('school_id', $request->user()->school_id)->exists(), 404);
        }
    }

    private function domain(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }
    }
}
