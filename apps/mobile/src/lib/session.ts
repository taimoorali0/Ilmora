import { Preferences } from '@capacitor/preferences';
import { reactive } from 'vue';

/**
 * Signed-in session kept on the device so the app opens offline.
 * Offline grant rules: docs/decisions.md D6.
 */
export interface SessionUser {
  id: string;
  name: string;
  school: { id: string; name: string; slug: string; status: string; logo_path: string | null } | null;
  branches: { id: string; name: string; code: string }[];
  roles: { role: string; branch_id: string | null }[];
}

export interface SessionData {
  token: string;
  user: SessionUser;
  device: { id: string; code: string; offline_valid_until: string };
  access: 'full' | 'read_only' | 'blocked';
}

const KEY = 'ilmora.session';

export const session = reactive<{ data: SessionData | null; loaded: boolean }>({ data: null, loaded: false });

export async function loadSession(): Promise<void> {
  const { value } = await Preferences.get({ key: KEY });
  session.data = value ? (JSON.parse(value) as SessionData) : null;
  session.loaded = true;
}

export async function saveSession(data: SessionData): Promise<void> {
  session.data = data;
  await Preferences.set({ key: KEY, value: JSON.stringify(data) });
}

export async function clearSession(): Promise<void> {
  session.data = null;
  await Preferences.remove({ key: KEY });
}

/** Offline grant expired → read-only until the device reconnects and revalidates. */
export function effectiveAccess(now = new Date()): 'full' | 'read_only' | 'blocked' | 'signed_out' {
  const s = session.data;
  if (!s) return 'signed_out';
  if (s.access === 'blocked') return 'blocked';
  if (new Date(s.device.offline_valid_until) < now) return 'read_only';
  return s.access;
}

export function hasRole(...roles: string[]): boolean {
  return !!session.data?.user.roles.some((r) => roles.includes(r.role));
}
