import { Network } from '@capacitor/network';
import { api, ApiError } from './api';
import { saveSession, session } from './session';
import { wipeLocal } from '@/powersync/db';

/**
 * Revalidates the offline grant whenever the device is online (docs/decisions.md D6):
 * extends offline_valid_until, applies read-only/blocked, wipes a revoked device.
 */
async function beat(): Promise<void> {
  if (!session.data) return;
  const status = await Network.getStatus();
  if (!status.connected) return;
  try {
    const res = await api<{ wipe: boolean; access: 'full' | 'read_only' | 'blocked'; offline_valid_until: string }>(
      '/sync/heartbeat',
      { method: 'POST' },
    );
    if (res.wipe) return wipeLocal();
    await saveSession({
      ...session.data,
      access: res.access,
      device: { ...session.data.device, offline_valid_until: res.offline_valid_until },
    });
  } catch (e) {
    if (e instanceof ApiError && e.status === 410) await wipeLocal();
    // other errors: stay offline-capable, try again later
  }
}

let timer: ReturnType<typeof setInterval> | null = null;
export function startHeartbeat(): void {
  if (timer) return;
  beat();
  timer = setInterval(beat, 15 * 60 * 1000);
  Network.addListener('networkStatusChange', (s) => { if (s.connected) beat(); });
}
