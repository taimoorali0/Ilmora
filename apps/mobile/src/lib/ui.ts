import { reactive } from 'vue';

/** Toasts and confirm dialogs drawn by App.vue (browser alert/confirm are blocked in embedded frames). */
export const ui = reactive({
  toasts: [] as { id: number; text: string; kind: 'ok' | 'bad' | 'info' }[],
  confirm: null as null | { title: string; message: string; okText: string; danger: boolean; resolve: (yes: boolean) => void },
});

let seq = 0;
export function toast(text: string, kind: 'ok' | 'bad' | 'info' = 'ok'): void {
  const id = ++seq;
  ui.toasts.push({ id, text, kind });
  setTimeout(() => { ui.toasts = ui.toasts.filter((t) => t.id !== id); }, kind === 'bad' ? 5000 : 3000);
}

export const fail = (e: unknown) => toast((e as Error)?.message || 'Something went wrong.', 'bad');

export function ask(title: string, message: string, okText = 'Confirm', danger = false): Promise<boolean> {
  return new Promise((resolve) => { ui.confirm = { title, message, okText, danger, resolve }; });
}

export function answer(yes: boolean): void {
  ui.confirm?.resolve(yes);
  ui.confirm = null;
}

/** Runs an action, shows its error as a toast, and reports whether it worked. */
export async function attempt(run: () => unknown | Promise<unknown>, done?: string): Promise<boolean> {
  try {
    await run();
    if (done) toast(done);
    return true;
  } catch (e) {
    fail(e);
    return false;
  }
}

export function printPage(): void {
  // Hosted previews run in a frame where the browser ignores print requests.
  let framed = false;
  try { framed = window.self !== window.top; } catch { framed = true; }
  if (framed) { toast('Printing is switched off in this hosted preview. It works in the installed app and in a normal browser tab.', 'info'); return; }
  try { window.print(); } catch { toast('Printing is not available here.', 'info'); }
}
