import { reactive } from 'vue';
import { kvGet, kvRemove, kvSet } from './kv';

/**
 * Signed-in session kept on the device so the app opens offline.
 * Offline grant rules: docs/decisions.md D6.
 */
export interface SessionUser {
  id: string;
  name: string;
  phone?: string | null;
  staff_id?: string | null;
  family_id?: string | null;
  /** Platform super admin: no school, sees the platform console only. */
  super?: boolean;
  school: { id: string; name: string; slug: string; status: string; logo_path?: string | null; phone?: string | null; address?: string | null } | null;
  branches: { id: string; name: string; code: string }[];
  roles: { role: string; branch_id: string | null }[];
}

export interface SessionData {
  token: string;
  user: SessionUser;
  device: { id: string; code: string; offline_valid_until: string };
  access: 'full' | 'read_only' | 'blocked';
}

/** Demo build: everything runs on this device with sample data, no server. */
export const IS_DEMO: boolean = import.meta.env?.MODE === 'demo' || import.meta.env?.VITE_DEMO === '1';

const KEY = 'ilmora.session';

export const session = reactive<{ data: SessionData | null; loaded: boolean }>({ data: null, loaded: false });

export async function loadSession(): Promise<void> {
  session.data = (await kvGet<SessionData>(KEY)) ?? null;
  session.loaded = true;
}

export async function saveSession(data: SessionData): Promise<void> {
  session.data = data;
  await kvSet(KEY, JSON.parse(JSON.stringify(data)));
}

export async function clearSession(): Promise<void> {
  session.data = null;
  await kvRemove(KEY);
}

/** Offline grant expired → read-only until the device reconnects and revalidates. */
export function effectiveAccess(now = new Date()): 'full' | 'read_only' | 'blocked' | 'signed_out' {
  const s = session.data;
  if (!s) return 'signed_out';
  if (s.access === 'blocked') return 'blocked';
  if (s.user.school && ['read_only', 'suspended', 'closed'].includes(s.user.school.status)) return 'read_only';
  if (new Date(s.device.offline_valid_until) < now) return 'read_only';
  return s.access;
}

export function hasRole(...roles: string[]): boolean {
  return !!session.data?.user.roles.some((r) => roles.includes(r.role));
}

export const canWrite = () => effectiveAccess() === 'full';

export function schoolId(): string | null {
  return session.data?.user.school?.id ?? null;
}

/** The branch new records go to: the user's branch role, else the school's first branch. */
export function defaultBranchId(): string | null {
  const u = session.data?.user;
  return u?.roles.find((r) => r.branch_id)?.branch_id ?? u?.branches[0]?.id ?? null;
}

export function branchCode(): string {
  const u = session.data?.user;
  const id = defaultBranchId();
  return u?.branches.find((b) => b.id === id)?.code ?? 'B1';
}
