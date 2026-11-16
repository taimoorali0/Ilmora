<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Services\Platform\SchoolProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Platform console for the Ilmora super admin (D10): only they create schools.
 * The same work can be done in the Filament panel at /admin.
 */
class PlatformController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::whereNull('school_id')->where('is_super_admin', true)->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || $user->status !== 'active') {
            throw ValidationException::withMessages(['email' => 'Wrong login or password.']);
        }

        return ['token' => $user->createToken('platform', ['platform'], now()->addDay())->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name]];
    }

    public function overview()
    {
        $counts = fn (string $table) => DB::table($table)->selectRaw('school_id, count(*) as n')->groupBy('school_id')->pluck('n', 'school_id');
        $students = DB::table('students')->where('status', 'active')->selectRaw('school_id, count(*) as n')->groupBy('school_id')->pluck('n', 'school_id');
        $staff = $counts('staff');
        $subs = DB::table('subscriptions')->orderBy('ends_on')->get()->keyBy('school_id');

        return [
            'plans' => DB::table('plans')->where('active', true)->orderBy('price_monthly')->get(),
            'schools' => School::orderBy('name')->get()->map(fn (School $s) => $s->only(['id', 'name', 'slug', 'phone', 'email', 'address', 'status', 'created_at']) + [
                'students' => (int) ($students[$s->id] ?? 0), 'staff' => (int) ($staff[$s->id] ?? 0),
                'plan_id' => $subs[$s->id]->plan_id ?? null, 'ends_on' => $subs[$s->id]->ends_on ?? null,
                'owner_phone' => DB::table('users as u')->join('user_roles as r', 'r.user_id', '=', 'u.id')
                    ->where('u.school_id', $s->id)->where('r.role', 'owner')->value('u.phone'),
            ]),
        ];
    }

    public function createSchool(Request $request, SchoolProvisioner $provisioner)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'slug' => 'required|alpha_dash|max:40|unique:schools,slug',
            'phone' => 'nullable|string|max:30', 'email' => 'nullable|email', 'address' => 'nullable|string|max:255',
            'branch_name' => 'nullable|string|max:80',
            'owner_name' => 'required|string|max:120', 'owner_phone' => 'required|string|max:20',
            'owner_password' => 'required|string|min:6',
            'plan_id' => 'required|uuid|exists:plans,id', 'ends_on' => 'nullable|date',
        ]);

        return ['id' => $provisioner->create($data, $request->user()->id)->id];
    }

    /** Suspend, make read-only or reactivate a school. */
    public function updateSchool(Request $request, School $school)
    {
        $data = $request->validate(['status' => 'required|in:active,suspended,read_only,closed']);
        $school->update($data);
        DB::table('audit_logs')->insert(['school_id' => $school->id, 'user_id' => $request->user()->id, 'action' => 'school_status',
            'table_name' => 'schools', 'row_id' => $school->id, 'after' => json_encode($data), 'created_at' => now()]);

        return ['ok' => true];
    }
}
