import type { AbstractPowerSyncDatabase, PowerSyncBackendConnector, PowerSyncCredentials } from '@powersync/web';
import { api, ApiError } from '@/lib/api';
import { session } from '@/lib/session';
import { reactive } from 'vue';

/** Tables the server treats as append-only: never send base_version (docs/decisions.md D2). */
const APPEND_ONLY = new Set(['payments', 'reversals']);
const SERVER_ONLY_COLUMNS = new Set(['school_id', 'version', 'created_at', 'updated_at', 'sync_state']);

export interface UploadResult {
  id: string;
  table: string;
  result: 'applied' | 'merged' | 'ignored_duplicate' | 'conflict' | 'rejected';
  message?: string;
}

/** Last upload outcome, shown in the Sync lab and the sync badge. */
export const uploadLog = reactive<{ last: UploadResult[]; at: string | null; error: string | null }>({
  last: [],
  at: null,
  error: null,
});

export class IlmoraConnector implements PowerSyncBackendConnector {
  constructor(private readonly onWipe: () => Promise<void>) {}

  async fetchCredentials(): Promise<PowerSyncCredentials | null> {
    if (!session.data) return null;
    try {
      const res = await api<{ token: string; expires_at: number; endpoint: string }>('/sync/token');
      return { endpoint: res.endpoint, token: res.token, expiresAt: new Date(res.expires_at * 1000) };
    } catch (e) {
      await this.handleAuthError(e);
      throw e;
    }
  }

  /**
   * Sends queued local writes. The server applies each op idempotently by row id,
   * so retrying a batch after a network failure is safe.
   */
  async uploadData(db: AbstractPowerSyncDatabase): Promise<void> {
    const batch = await db.getCrudBatch(200);
    if (!batch) return;

    const ops = [];
    for (const entry of batch.crud) {
      const data = Object.fromEntries(
        Object.entries(entry.opData ?? {}).filter(([k]) => !SERVER_ONLY_COLUMNS.has(k)),
      );
      let baseVersion: number | undefined;
      if (!APPEND_ONLY.has(entry.table) && entry.op !== 'PUT') {
        // Devices never change `version`, so the local value is the version this edit started from.
        const row = await db.getOptional<{ version: number }>(`SELECT version FROM ${entry.table} WHERE id = ?`, [entry.id]);
        baseVersion = row?.version ?? 0;
      }
      ops.push({ op: entry.op, table: entry.table, id: entry.id, data, base_version: baseVersion });
    }

    try {
      const res = await api<{ results: UploadResult[] }>('/sync/upload', { method: 'POST', json: { ops } });
      uploadLog.last = res.results;
      uploadLog.at = new Date().toISOString();
      uploadLog.error = null;
      // Conflicts and rejections are recorded server-side; the queue must still move on,
      // otherwise one bad row would block every later write from this device.
      await batch.complete();
    } catch (e) {
      uploadLog.error = e instanceof Error ? e.message : String(e);
      await this.handleAuthError(e);
      throw e; // PowerSync retries the same batch later
    }
  }

  private async handleAuthError(e: unknown) {
    if (e instanceof ApiError && (e.status === 410 || (e.body as { wipe?: boolean } | null)?.wipe)) {
      await this.onWipe();
    }
  }
}
