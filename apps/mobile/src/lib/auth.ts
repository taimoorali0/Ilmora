import { api } from './api';
import { clearSession, IS_DEMO, saveSession, session, type SessionData } from './session';
import { loadStore, raw, replaceAll, uuid, wipeStore, type Row } from './store';
import { startSync, syncNow } from './sync';

/** Sign-in for school users and for the platform super admin; demo logins never leave the device. */
const FAR = '2099-01-01T00:00:00.000Z';

function demoSession(user: Row): SessionData {
  const school = raw('schools').find((s) => s.id === user.school_id) ?? null;
  return {
    token: 'demo',
    user: {
      id: user.id, name: user.name, phone: user.phone, staff_id: user.staff_id, family_id: user.family_id, super: !!user.is_super_admin,
      school: school && { id: school.id, name: school.name, slug: school.slug, status: school.status, phone: school.phone, address: school.address },
      branches: raw('branches').filter((b) => b.school_id === user.school_id).map((b) => ({ id: b.id, name: b.name, code: b.code })),
      roles: raw('user_roles').filter((r) => r.user_id === user.id).map((r) => ({ role: r.role, branch_id: r.branch_id })),
    },
    device: { id: uuid(), code: 'D01', offline_valid_until: FAR },
    access: 'full',
  };
}

export async function login(school: string, loginId: string, password: string): Promise<void> {
  if (IS_DEMO) {
    const s = raw('schools').find((x) => x.slug === school.trim().toLowerCase());
    const user = s && raw('users').find((u) => u.school_id === s.id && (u.phone === loginId.trim() || u.email === loginId.trim()));
    if (!user || user.password !== password || user.status === 'disabled') throw new Error('Wrong login or password.');
    if (['suspended', 'closed'].includes(s!.status)) throw new Error('This school account is not active. Contact Ilmora support.');
    return saveSession(demoSession(user));
  }
  const res = await api<Omit<SessionData, 'access'>>('/auth/login', {
    method: 'POST',
    json: { login: loginId.trim(), password, school: school.trim().toLowerCase(), device_id: (await previousDevice()) ?? undefined,
      device_name: navigator.userAgent.slice(0, 80), platform: 'web' },
  });
  await wipeStore();
  await loadStore();
  await saveSession({ ...res, access: 'full' });
  localStorageSet('ilmora.device', res.device.id);
  startSync();
  await syncNow();
}

export async function loginPlatform(email: string, password: string): Promise<void> {
  if (IS_DEMO) {
    const user = raw('users').find((u) => u.is_super_admin && u.email === email.trim().toLowerCase());
    if (!user || user.password !== password) throw new Error('Wrong login or password.');
    return saveSession(demoSession(user));
  }
  const res = await api<{ token: string; user: { id: string; name: string } }>('/platform/login', { method: 'POST', json: { email, password } });
  await wipeStore();
  await loadStore();
  await saveSession({ token: res.token, user: { ...res.user, super: true, school: null, branches: [], roles: [] },
    device: { id: uuid(), code: 'ADM', offline_valid_until: FAR }, access: 'full' });
  await refreshPlatform();
}

/** Platform console data from the server (demo keeps it all on the device already). */
export async function refreshPlatform(): Promise<void> {
  if (IS_DEMO) return;
  const res = await api<{ plans: Row[]; schools: Row[] }>('/platform/overview');
  replaceAll({ plans: res.plans, schools: res.schools });
}

export async function logout(): Promise<void> {
  if (!IS_DEMO) {
    if (!session.data?.user.super) await api('/auth/logout', { method: 'POST' }).catch(() => undefined);
    await wipeStore(); // D6: nothing stays on a signed-out device
  }
  await clearSession();
  if (!IS_DEMO) location.reload(); // the demo stays on the page: embedded frames cannot always reload
}

async function previousDevice(): Promise<string | null> {
  try { return localStorage.getItem('ilmora.device'); } catch { return null; }
}
function localStorageSet(key: string, value: string) {
  try { localStorage.setItem(key, value); } catch { /* ignore */ }
}
