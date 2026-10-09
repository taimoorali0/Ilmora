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

        if (! $device) {
            return response()->json(['message' => 'Unknown device. Sign in again.'], 401);
        }
        if ($device->revoked_at || $device->wipe_on_contact) {
            return response()->json(['message' => 'Device removed.', 'wipe' => true], 410);
        }

        $request->attributes->set('device', $device);
        return $next($request);
    }
}
