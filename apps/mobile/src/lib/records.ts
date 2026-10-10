import type { AbstractPowerSyncDatabase } from '@powersync/web';
import { session } from './session';

/** Roles that may add or edit students and families (server enforces the same via abilities). */
export const EDITOR_ROLES = ['owner', 'principal', 'admin'] as const;

/** CNIC and B-Form share the NADRA format 12345-1234567-1. */
export const CNIC_RE = /^\d{5}-\d{7}-\d$/;
export const PHONE_RE = /^(\+92|0)3\d{2}-?\d{7}$/;

export function normalizePhone(v: string): string {
  return v.replace(/[\s-]/g, '');
}

/** Formats 13 digits as 12345-1234567-1 while typing; leaves other input untouched. */
export function formatCnic(v: string): string {
  const d = v.replace(/\D/g, '').slice(0, 13);
  if (d.length <= 5) return d;
  if (d.length <= 12) return `${d.slice(0, 5)}-${d.slice(5)}`;
  return `${d.slice(0, 5)}-${d.slice(5, 12)}-${d.slice(12)}`;
}

/**
 * Next number from a device-local counter. Codes include the device code so two
 * offline devices never hand out the same family code or admission number (D4).
 */
export async function nextCode(db: AbstractPowerSyncDatabase, counter: string, prefix: string): Promise<string> {
  const n = await db.writeTransaction(async (tx) => {
    const row = await tx.getOptional<{ value: number }>('SELECT value FROM local_counters WHERE id = ?', [counter]);
    const next = (row?.value ?? 0) + 1;
    if (row) await tx.execute('UPDATE local_counters SET value = ? WHERE id = ?', [next, counter]);
    else await tx.execute('INSERT INTO local_counters (id, value) VALUES (?, ?)', [counter, next]);
    return next;
  });
  const device = session.data?.device.code ?? 'D00';
  return `${prefix}-${device}-${String(n).padStart(4, '0')}`;
}

/** The branch new records go to: the user's branch role, else the school's first branch. */
export function defaultBranchId(): string | null {
  const u = session.data?.user;
  return u?.roles.find((r) => r.branch_id)?.branch_id ?? u?.branches[0]?.id ?? null;
}
