import { session } from './session';

export const API_URL: string = import.meta.env?.VITE_API_URL ?? 'http://localhost:8000/api/v1';

export class ApiError extends Error {
  constructor(public status: number, message: string, public body: unknown = null) {
    super(message);
  }
}

/** JSON request to the Laravel API with token + device headers. */
export async function api<T = unknown>(path: string, init: RequestInit & { json?: unknown } = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.json !== undefined) headers.set('Content-Type', 'application/json');
  if (session.data) {
    headers.set('Authorization', `Bearer ${session.data.token}`);
    headers.set('X-Device-Id', session.data.device.id);
  }
  let res: Response;
  try {
    res = await fetch(`${API_URL}${path}`, { ...init, headers, body: init.json !== undefined ? JSON.stringify(init.json) : init.body });
  } catch {
    throw new ApiError(0, 'No connection to the server.');
  }
  const body = res.status === 204 ? null : await res.json().catch(() => null);
  if (!res.ok) {
    const b = body as { message?: string; errors?: Record<string, string[]> } | null;
    const first = b?.errors ? Object.values(b.errors)[0]?.[0] : null;
    throw new ApiError(res.status, first ?? b?.message ?? `Request failed (${res.status})`, body);
  }
  return body as T;
}
