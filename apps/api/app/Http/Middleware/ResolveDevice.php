<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires X-Device-Id belonging to the signed-in user and not revoked.
 * A revoked device gets 410 with wipe=true so the app clears local data.
 */
class ResolveDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = Device::where('id', $request->header('X-Device-Id'))
            ->where('user_id', $request->user()?->id)
            ->first();

        // The API token is issued per device; it must not be replayed with another device id.
        $tokenName = $request->user()?->currentAccessToken()?->name;
        if (! $device || $tokenName !== "device:{$device->id}") {
            return response()->json(['message' => 'Unknown device. Sign in again.'], 401);
        }
        $user = $request->user();
        if ($user->status !== 'active' || in_array($user->school?->status, ['suspended', 'closed', null], true)) {
            return response()->json(['message' => 'Access blocked. Contact the school admin.', 'wipe' => false], 403);
        }
        if ($device->revoked_at || $device->wipe_on_contact) {
            return response()->json(['message' => 'Device removed.', 'wipe' => true], 410);
        }

        $request->attributes->set('device', $device);
        return $next($request);
    }
}
