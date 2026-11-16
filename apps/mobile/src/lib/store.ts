import { computed, reactive, shallowRef, triggerRef, type ComputedRef, type ShallowRef } from 'vue';
import { kvGet, kvRemove, kvSet } from './kv';
import { defaultBranchId, IS_DEMO, schoolId, session } from './session';

/**
 * The device's local database. Every screen reads and writes here, so the app
 * works without internet. Writes are queued in the outbox and uploaded by
 * lib/sync.ts; the server answers with applied / merged / conflict / rejected
 * (docs/decisions.md D2). Rows use the same column names as the server tables.
 */
export interface Row { id: string; [key: string]: any }

export interface Op {
  op: 'PUT' | 'PATCH';
  table: string;
  id: string;
  data: Record<string, unknown>;
  sending?: boolean;
}

export const TABLES = [
  // platform (super admin)
  'plans', 'schools', 'subscriptions', 'users', 'user_roles', 'branches',
  // setup
  'academic_sessions', 'classes', 'sections', 'subjects', 'section_subjects', 'holidays',
  // students
  'families', 'guardians', 'students', 'enrollments', 'student_attendance',
  // learning
  'exams', 'exam_subjects', 'marks', 'exam_papers', 'syllabus_topics', 'homework', 'notices', 'messages',
  // fees
  'fee_heads', 'fee_structures', 'discount_rules', 'student_discounts', 'fine_rules', 'invoices', 'invoice_lines',
  'payments', 'reversals', 'expenses',
  // staff
  'staff', 'shifts', 'leave_types', 'staff_leaves', 'staff_attendance_days', 'staff_loans', 'payroll_runs', 'payslips',
] as const;
export type TableName = (typeof TABLES)[number];

/** Tables whose rows belong to the whole school, not one campus. */
const NO_BRANCH = new Set<string>(['plans', 'schools', 'subscriptions', 'users', 'user_roles', 'branches',
  'academic_sessions', 'subjects', 'fee_heads', 'discount_rules', 'fine_rules', 'leave_types']);
/** Tables shared by all schools on this device (demo and platform console). */
const GLOBAL = new Set<string>(['plans', 'schools']);
/** Written by the server only: local copies are never uploaded. */
const SERVER_TABLES = new Set<string>(['plans', 'schools', 'subscriptions', 'users', 'user_roles', 'branches',
  'invoices', 'invoice_lines', 'payroll_runs', 'payslips']);
const APPEND_ONLY = new Set<string>(['payments', 'reversals']);
const LOCAL_COLUMNS = new Set(['id', 'school_id', 'version', 'created_at', 'updated_at', 'created_by', 'updated_by']);
/** Columns only the server may set (approvals, delivery status): never uploaded. */
const LOCKED: Record<string, string[]> = {
  payments: ['sync_state', 'received_by'], messages: ['status', 'sent_at', 'error'],
  staff_leaves: ['status', 'decided_by', 'decided_at'], staff_attendance_days: ['locked'],
  staff_loans: ['status', 'approved_by'], student_discounts: ['approved_by'], reversals: ['approved_by'],
};

const data = Object.fromEntries(TABLES.map((t) => [t, shallowRef<Row[]>([])])) as Record<TableName, ShallowRef<Row[]>>;

export const meta = reactive({
  ready: false,
  pending: 0,
  syncing: false,
  lastSync: null as string | null,
  syncError: null as string | null,
  /** Problems the server reported for uploaded changes, newest first. */
  issues: [] as { table: string; id: string; result: string; message?: string; at: string }[],
});

let outbox: Op[] = [];
let cursor: string | null = null;
let counters: Record<string, number> = {};

const scoped = Object.fromEntries(TABLES.map((t) => [t, computed(() => {
  const sid = schoolId();
  const rows = data[t].value;
  return GLOBAL.has(t) || !sid ? rows : rows.filter((r) => r.school_id === sid);
})])) as Record<TableName, ComputedRef<Row[]>>;

const indexes = Object.fromEntries(TABLES.map((t) => [t, computed(() => new Map(data[t].value.map((r) => [r.id, r])))])) as
  Record<TableName, ComputedRef<Map<string, Row>>>;

/** Rows of the signed-in school (reactive). */
export function all(table: TableName): Row[] {
  return scoped[table].value;
}

/** Every row on the device regardless of school: login and the platform console. */
export function raw(table: TableName): Row[] {
  return data[table].value;
}

export function find(table: TableName, id: string | null | undefined): Row | undefined {
  return id ? indexes[table].value.get(id) : undefined;
}

export function where(table: TableName, test: Partial<Row> | ((r: Row) => boolean)): Row[] {
  if (typeof test === 'function') return all(table).filter(test);
  const keys = Object.keys(test);
  return all(table).filter((r) => keys.every((k) => r[k] === test[k]));
}

/** Groups a table by a column, cached until the table changes. */
const groupCache = new Map<string, ComputedRef<Map<any, Row[]>>>();
export function groupBy(table: TableName, column: string): Map<any, Row[]> {
  const key = `${table}.${column}`;
  let c = groupCache.get(key);
  if (!c) {
    c = computed(() => {
      const m = new Map<any, Row[]>();
      for (const r of all(table)) {
        const list = m.get(r[column]);
        if (list) list.push(r); else m.set(r[column], [r]);
      }
      return m;
    });
    groupCache.set(key, c);
  }
  return c.value;
}

/** A row plus extra fields, keeping the row's columns typed as a row. */
export const mix = <T extends object>(row: Row, extra: T): Row & T => ({ ...row, ...extra }) as Row & T;

export function uuid(): string {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

let changed: (() => void) | null = null;
/** Called after every queued change (lib/sync.ts uses it to upload soon). */
export function onQueued(fn: () => void) { changed = fn; }

function queue(op: 'PUT' | 'PATCH', table: string, id: string, values: Record<string, unknown>) {
  if (IS_DEMO || SERVER_TABLES.has(table)) return;
  const locked = LOCKED[table] ?? [];
  const clean = Object.fromEntries(Object.entries(values).filter(([k]) => !LOCAL_COLUMNS.has(k) && !locked.includes(k)));
  if (!Object.keys(clean).length) return;
  // Fold into an unsent change of the same row, so one row uploads once with one base version.
  const waiting = outbox.find((o) => o.table === table && o.id === id && !o.sending);
  if (waiting) Object.assign(waiting.data, clean);
  else outbox.push({ op, table, id, data: clean });
  meta.pending = outbox.length;
  changed?.();
}

export function insert(table: TableName, values: Record<string, any>, opts: { local?: boolean } = {}): Row {
  const now = new Date().toISOString();
  const row: Row = { id: values.id ?? uuid(), version: 0, created_at: now, updated_at: now, ...values };
  if (!GLOBAL.has(table) && row.school_id === undefined) row.school_id = schoolId();
  if (!NO_BRANCH.has(table) && row.branch_id === undefined) row.branch_id = defaultBranchId();
  data[table].value.push(row);
  triggerRef(data[table]);
  if (!opts.local) queue('PUT', table, row.id, row);
  save();
  return row;
}

export function update(table: TableName, id: string, patch: Record<string, any>, opts: { local?: boolean } = {}): Row | undefined {
  const rows = data[table].value;
  const i = rows.findIndex((r) => r.id === id);
  if (i < 0) return undefined;
  rows[i] = { ...rows[i], ...patch, updated_at: new Date().toISOString() };
  triggerRef(data[table]);
  if (!opts.local && !APPEND_ONLY.has(table)) queue('PATCH', table, id, patch);
  save();
  return rows[i];
}

/** Insert or update the row matching `match` (for example one attendance row per student per day). */
export function upsert(table: TableName, match: Record<string, any>, values: Record<string, any>): Row {
  const keys = Object.keys(match);
  const existing = all(table).find((r) => keys.every((k) => r[k] === match[k]));
  return existing ? update(table, existing.id, values)! : insert(table, { ...match, ...values });
}

/** Next number from a counter kept on this device (receipt numbers, family codes: D4). */
export function nextCounter(name: string): number {
  const key = `${schoolId() ?? 'x'}:${name}`;
  counters[key] = (counters[key] ?? 0) + 1;
  save();
  return counters[key];
}

// ---------- used by lib/sync.ts ----------

export function takeBatch(limit = 200): (Op & { base_version?: number })[] {
  const batch = outbox.filter((o) => !o.sending).slice(0, limit);
  return batch.map((o) => {
    o.sending = true;
    const row = indexes[o.table as TableName].value.get(o.id);
    return { ...o, base_version: o.op === 'PATCH' && !APPEND_ONLY.has(o.table) ? (row?.version ?? 0) : undefined };
  });
}

export function finishBatch(results: { id: string; table: string; result: string; message?: string }[] | null) {
  if (!results) {
    outbox.forEach((o) => { o.sending = false; }); // upload failed: try the same changes again later
    return;
  }
  for (const r of results) {
    const row = indexes[r.table as TableName]?.value.get(r.id);
    if (row && (r.result === 'applied' || r.result === 'merged')) row.version = (row.version ?? 0) + 1;
    if (r.result === 'conflict' || r.result === 'rejected') {
      meta.issues.unshift({ ...r, at: new Date().toISOString() });
      meta.issues.splice(50);
    }
  }
  outbox = outbox.filter((o) => !o.sending);
  meta.pending = outbox.length;
  save();
}

export function getCursor(): string | null {
  return cursor;
}

/** Merge rows from the server. Local changes still waiting to upload stay on top. */
export function mergePull(tables: Record<string, Row[]>, nextCursor: string) {
  for (const [table, rows] of Object.entries(tables)) {
    const ref = data[table as TableName];
    if (!ref || !rows.length) continue;
    const index = new Map(ref.value.map((r, i) => [r.id, i]));
    for (const row of rows) {
      const pending = outbox.find((o) => o.table === table && o.id === row.id);
      const merged = pending ? { ...row, ...pending.data } : row;
      const i = index.get(row.id);
      if (i === undefined) { index.set(row.id, ref.value.length); ref.value.push(merged); } else ref.value[i] = merged;
    }
    triggerRef(ref);
  }
  cursor = nextCursor;
  meta.lastSync = new Date().toISOString();
  save();
}

// ---------- persistence ----------

const KEY = 'ilmora.db';
let timer: ReturnType<typeof setTimeout> | null = null;

function snapshot() {
  return { tables: Object.fromEntries(TABLES.map((t) => [t, data[t].value])), outbox, cursor, counters, lastSync: meta.lastSync };
}

function save() {
  if (!meta.ready || timer) return;
  timer = setTimeout(() => {
    timer = null;
    kvSet(KEY, JSON.parse(JSON.stringify(snapshot()))).catch(() => undefined);
  }, 400);
}

/** Write to storage now (before a reload). */
export async function flushStore(): Promise<void> {
  if (timer) { clearTimeout(timer); timer = null; }
  if (meta.ready) await kvSet(KEY, JSON.parse(JSON.stringify(snapshot()))).catch(() => undefined);
}

export async function loadStore(): Promise<boolean> {
  const saved = await kvGet<ReturnType<typeof snapshot>>(KEY);
  if (saved?.tables) replaceAll(saved.tables as Record<string, Row[]>);
  outbox = (saved?.outbox ?? []).map((o) => ({ ...o, sending: false }));
  cursor = saved?.cursor ?? null;
  counters = saved?.counters ?? {};
  meta.lastSync = saved?.lastSync ?? null;
  meta.pending = outbox.length;
  meta.ready = true;
  return !!saved?.tables;
}

export function replaceAll(tables: Record<string, Row[]>) {
  for (const t of TABLES) data[t].value = tables[t] ? [...tables[t]] : [];
}

export function exportAll(): Record<string, Row[]> {
  return Object.fromEntries(TABLES.map((t) => [t, data[t].value]));
}

/** Sign-out, revoked device or "reset demo": remove everything from this device (D6). */
export async function wipeStore(): Promise<void> {
  replaceAll({});
  outbox = [];
  cursor = null;
  counters = {};
  meta.pending = 0;
  meta.lastSync = null;
  meta.issues = [];
  if (timer) { clearTimeout(timer); timer = null; }
  await kvRemove(KEY);
}

export { session };
