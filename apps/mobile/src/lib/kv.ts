/**
 * Small key-value storage on the device: IndexedDB when it is available, otherwise
 * localStorage, otherwise memory (private windows and embedded previews can block both).
 * Values are plain JSON-like objects.
 */
const memory = new Map<string, unknown>();
let dbPromise: Promise<IDBDatabase | null> | null = null;

function open(): Promise<IDBDatabase | null> {
  dbPromise ??= new Promise((resolve) => {
    try {
      if (typeof indexedDB === 'undefined') return resolve(null);
      const req = indexedDB.open('ilmora', 1);
      req.onupgradeneeded = () => req.result.createObjectStore('kv');
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
      req.onblocked = () => resolve(null);
    } catch {
      resolve(null);
    }
  });
  return dbPromise;
}

function tx<T>(mode: IDBTransactionMode, run: (s: IDBObjectStore) => IDBRequest<T>): Promise<T | undefined> {
  return open().then((db) => new Promise<T | undefined>((resolve, reject) => {
    if (!db) return reject(new Error('no indexeddb'));
    try {
      const req = run(db.transaction('kv', mode).objectStore('kv'));
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    } catch (e) {
      reject(e);
    }
  }));
}

export async function kvGet<T>(key: string): Promise<T | undefined> {
  try {
    return (await tx<T>('readonly', (s) => s.get(key))) ?? undefined;
  } catch {
    try {
      const v = localStorage.getItem(key);
      if (v !== null) return JSON.parse(v) as T;
    } catch { /* fall through to memory */ }
    return memory.get(key) as T | undefined;
  }
}

export async function kvSet(key: string, value: unknown): Promise<void> {
  memory.set(key, value);
  try {
    await tx('readwrite', (s) => s.put(value, key));
  } catch {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* memory only */ }
  }
}

export async function kvRemove(key: string): Promise<void> {
  memory.delete(key);
  try { await tx('readwrite', (s) => s.delete(key)); } catch { /* ignore */ }
  try { localStorage.removeItem(key); } catch { /* ignore */ }
}
