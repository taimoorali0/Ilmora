<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Sync\PowerSyncToken;
use App\Services\Sync\SyncWriter;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    /** Called by the app's PowerSync connector (fetchCredentials). */
    public function token(Request $request, PowerSyncToken $powersync)
    {
        return $powersync->issue($request->user(), $request->attributes->get('device'));
    }

    /** Called by the app's PowerSync connector (uploadData) with queued local writes. */
    public function upload(Request $request, SyncWriter $writer)
    {
        $data = $request->validate([
            'ops' => 'required|array|max:500',
            'ops.*.op' => 'required|in:PUT,PATCH,DELETE',
            'ops.*.table' => 'required|string',
            'ops.*.id' => 'required|string',
            'ops.*.data' => 'nullable|array',
            'ops.*.base_version' => 'nullable|integer',
        ]);

        return ['results' => $writer->apply($request->user(), $request->attributes->get('device'), $data['ops'])];
    }

    /**
     * Revalidate the offline grant (D6). The app calls this whenever it is online.
     * A revoked device or disabled user gets wipe=true and must clear local data.
     */
    public function heartbeat(Request $request)
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');
        $user = $request->user();
        $school = $user->school;

        $blocked = $user->status !== 'active' || in_array($school->status, ['suspended', 'closed'], true);
        if (! $blocked) {
            $device->update([
                'offline_valid_until' => now()->addDays($school->offline_access_days),
                'last_seen_at' => now(),
            ]);
        }

        return [
            'wipe' => $device->wipe_on_contact,
            'access' => $blocked ? 'blocked' : ($school->status === 'read_only' ? 'read_only' : 'full'),
            'offline_valid_until' => $device->offline_valid_until,
            'server_time' => now(),
        ];
    }
}
