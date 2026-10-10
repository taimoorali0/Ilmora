<?php

namespace App\Services\Sync;

use App\Models\Device;
use App\Models\User;

/**
 * HS256 JWT for the PowerSync service. Sync rules read `sub` (user id) and
 * look up the user's school, roles and branches in Postgres (infra/powersync/sync-rules.yaml).
 */
final class PowerSyncToken
{
    public function issue(User $user, Device $device): array
    {
        $cfg = config('ilmora.powersync');
        if (empty($cfg['secret']) || str_starts_with($cfg['secret'], 'change-me') || strlen(self::b64decode($cfg['secret'])) < 32) {
            throw new \RuntimeException('POWERSYNC_JWT_SECRET is missing, a placeholder, or shorter than 32 bytes.');
        }
        $now = time();
        $exp = $now + 60 * (int) $cfg['ttl_minutes'];

        $header = ['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $cfg['kid']];
        $claims = [
            'sub' => $user->id,
            'aud' => $cfg['url'],
            'iat' => $now,
            'exp' => $exp,
            'school_id' => $user->school_id,
            'device_id' => $device->id,
        ];

        $segments = [self::b64(json_encode($header)), self::b64(json_encode($claims))];
        $secret = self::b64decode($cfg['secret']);
        $segments[] = self::b64(hash_hmac('sha256', implode('.', $segments), $secret, true));

        return ['token' => implode('.', $segments), 'expires_at' => $exp, 'endpoint' => $cfg['url']];
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64decode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
