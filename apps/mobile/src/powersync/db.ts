import { PowerSyncDatabase } from '@powersync/capacitor';
import { AppSchema } from './schema';
import { IlmoraConnector } from './connector';
import { clearSession } from '@/lib/session';

/**
 * One local database per device. On Android/iOS the Capacitor SDK uses native SQLite;
 * in the browser it falls back to the Web SDK (WASQLite in IndexedDB/OPFS).
 * NOTE: @powersync/capacitor is a beta package — this is exactly what the spike validates.
 */
export const db = new PowerSyncDatabase({
  schema: AppSchema,
  database: { dbFilename: 'ilmora.sqlite' },
});

/** Revoked device / removed user: wipe all local data (docs/decisions.md D6). */
export async function wipeLocal(): Promise<void> {
  await db.disconnectAndClear();
  await clearSession();
  window.location.assign('/login');
}

let connected = false;
export async function connectSync(): Promise<void> {
  if (connected) return;
  connected = true;
  await db.connect(new IlmoraConnector(wipeLocal));
}

export async function disconnectSync(): Promise<void> {
  connected = false;
  await db.disconnect();
}
