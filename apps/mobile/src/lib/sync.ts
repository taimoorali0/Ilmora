import { api, ApiError } from './api';
import { clearSession, IS_DEMO, saveSession, session } from './session';
import { finishBatch, getCursor, mergePull, meta, onQueued, takeBatch, wipeStore, type Row } from './store';

/**
 * Keeps this device and the server in step (docs/decisions.md D2, D6):
 * upload queued changes, download what changed, revalidate the offline grant.
 * Safe to call at any time; it does nothing offline and never runs twice at once.
 */
export async function wipeDevice(): Promise<void> {
  await wipeStore();
  await clearSession();
  if (typeof location !== 'undefined') location.reload();
}

async function push(): Promise<void> {
  for (let round = 0; round < 20; round++) {
    const batch = takeBatch();
    if (!batch.length) return;
    try {
      const res = await api<{ results: { id: string; table: string; result: string; message?: string }[] }>('/sync/upload', {
        method: 'POST', json: { ops: batch.map(({ op, table, id, data, base_version }) => ({ op, table, id, data, base_version })) },
      });
      finishBatch(res.results);
    } catch (e) {
      finishBatch(null);
      throw e;
    }
  }
}

export async function pull(): Promise<void> {
  const since = getCursor();
  const res = await api<{ cursor: string; tables: Record<string, Row[]> }>(`/sync/pull${since ? `?since=${encodeURIComponent(since)}` : ''}`);
  mergePull(res.tables, res.cursor);
}

async function heartbeat(): Promise<void> {
  const res = await api<{ wipe: boolean; access: 'full' | 'read_only' | 'blocked'; offline_valid_until: string }>('/sync/heartbeat', { method: 'POST' });
  if (res.wipe) return wipeDevice();
  if (session.data) {
    await saveSession({ ...session.data, access: res.access, device: { ...session.data.device, offline_valid_until: res.offline_valid_until } });
  }
}

export async function syncNow(): Promise<void> {
  if (IS_DEMO || !session.data || session.data.user.super || meta.syncing) return;
  if (typeof navigator !== 'undefined' && navigator.onLine === false) return;
  meta.syncing = true;
  try {
    await push();
    await pull();
    await heartbeat();
    meta.syncError = null;
  } catch (e) {
    if (e instanceof ApiError && (e.status === 410 || (e.body as { wipe?: boolean } | null)?.wipe)) return wipeDevice();
    if (e instanceof ApiError && e.status === 401) { await clearSession(); location.reload(); return; }
    meta.syncError = e instanceof ApiError && e.status === 0 ? null : (e as Error).message; // offline is not an error
  } finally {
    meta.syncing = false;
  }
}

let started = false;
let soon: ReturnType<typeof setTimeout> | null = null;

/** Sync shortly after a local change, so several quick edits upload together. */
export function syncSoon(): void {
  if (IS_DEMO || soon) return;
  soon = setTimeout(() => { soon = null; syncNow(); }, 2500);
}

export function startSync(): void {
  if (IS_DEMO || started) return;
  started = true;
  onQueued(syncSoon);
  syncNow();
  setInterval(syncNow, 60_000);
  window.addEventListener('online', () => syncNow());
  document.addEventListener('visibilitychange', () => { if (!document.hidden) syncNow(); });
}
