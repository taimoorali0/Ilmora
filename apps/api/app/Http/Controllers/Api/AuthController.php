<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\Sync\PowerSyncToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** bcrypt of a random string; only used to equalise timing for unknown logins. */
    private const DUMMY_HASH = '$2y$12$AT6dYpUXNzI/ePODPkD/tOdZ.Uy8ORlCisSqwrdzMiYtRScH4L1pu';

    /**
     * Login from a phone or browser. Registers (or reuses) the device and returns
     * the API token, offline grant (D6) and a PowerSync token.
     */
    public function login(Request $request, PowerSyncToken $powersync)
    {
        $data = $request->validate([
            'login' => 'required|string',          // phone or email
            'password' => 'required|string',
            'school' => 'required|string',         // school slug
            'device_id' => 'nullable|uuid',
            'device_name' => 'nullable|string|max:80',
            'platform' => 'required|in:android,ios,web,desktop',
        ]);

        $schoolId = DB::table('schools')->where('slug', $data['school'])->value('id');
        // School users only: super admins (school_id null) use the admin panel, never the app API.
        $user = $schoolId ? User::where('school_id', $schoolId)
            ->where(fn ($q) => $q->where('phone', $data['login'])->orWhere('email', $data['login']))
            ->first() : null;

        // Always run one hash check so response time does not reveal whether the login exists.
        $passwordOk = Hash::check($data['password'], $user->password ?? self::DUMMY_HASH);
        if (! $user || ! $passwordOk || $user->status !== 'active') {
            throw ValidationException::withMessages(['login' => 'Wrong login or password.']);
        }
        if (in_array($user->school->status, ['suspended', 'closed'], true)) {
            throw ValidationException::withMessages(['login' => 'This school account is not active. Contact Ilmora support.']);
        }

        $device = $this->device($user, $data);
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken("device:{$device->id}", ['*'], now()->addDays(config('ilmora.token_days')))->plainTextToken,
            'user' => $this->profile($user),
            'device' => $device->only(['id', 'code', 'offline_valid_until']),
            'powersync' => $powersync->issue($user, $device),
        ]);
    }

    public function me(Request $request)
    {
        return ['user' => $this->profile($request->user())];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->noContent();
    }

    private function device(User $user, array $data): Device
    {
        $device = isset($data['device_id'])
            ? Device::where('id', $data['device_id'])->where('user_id', $user->id)->first()
            : null;
        $validUntil = now()->addDays($user->school->offline_access_days ?? config('ilmora.offline_access_days'));

        if ($device) {
            if ($device->revoked_at) {
                throw ValidationException::withMessages(['device' => 'This device was removed. Contact the school admin.']);
            }
            $device->update(['offline_valid_until' => $validUntil, 'last_seen_at' => now()]);
            return $device;
        }

        return DB::transaction(function () use ($user, $data, $validUntil) {
            // D01, D02... per school; lock to avoid two logins taking the same code.
            $count = DB::table('devices')->where('school_id', $user->school_id)->lockForUpdate()->count();
            return Device::create([
                'school_id' => $user->school_id,
                'branch_id' => $user->roles->firstWhere('branch_id', '!=', null)?->branch_id,
                'user_id' => $user->id,
                'code' => sprintf('D%02d', $count + 1),
                'name' => $data['device_name'] ?? null,
                'platform' => $data['platform'],
                'offline_valid_until' => $validUntil,
                'last_seen_at' => now(),
            ]);
        });
    }

    private function profile(User $user): array
    {
        $user->loadMissing('roles', 'school.branches');
        return [
            'id' => $user->id,
            'name' => $user->name,
            'school' => $user->school?->only(['id', 'name', 'slug', 'status', 'logo_path']),
            'branches' => $user->school?->branches->map->only(['id', 'name', 'code'])->values(),
            'roles' => $user->roles->map->only(['role', 'branch_id'])->values(),
        ];
    }
}
