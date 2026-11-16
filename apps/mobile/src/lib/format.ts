/** Display helpers. Money is whole rupees (integers). */
export const money = (n: number | null | undefined) => 'Rs ' + Math.round(n ?? 0).toLocaleString('en-PK');

const pad = (n: number) => String(n).padStart(2, '0');
/** Local calendar date as YYYY-MM-DD (never use toISOString for dates: it shifts across midnight in PKT). */
export const ymd = (d: Date = new Date()) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
export const today = () => ymd();
/** Local calendar day (YYYY-MM-DD) of a stored UTC timestamp. */
export const localDay = (iso: string | null | undefined) => (iso ? ymd(new Date(iso)) : '');
export const period = (d: Date = new Date()) => ymd(d).slice(0, 7);
export const addDays = (date: string, days: number) => {
  const d = new Date(date + 'T12:00:00');
  d.setDate(d.getDate() + days);
  return ymd(d);
};
export const addMonths = (p: string, months: number) => {
  const d = new Date(p.slice(0, 7) + '-15T12:00:00');
  d.setMonth(d.getMonth() + months);
  return period(d);
};

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
/** 2026-11-15 → 15 Nov 2026 */
export const nice = (date: string | null | undefined) => {
  if (!date) return '';
  // Timestamps are stored in UTC: show the local calendar day.
  const [y, m, d] = (date.length > 10 ? ymd(new Date(date)) : date).split('-').map(Number);
  return `${d} ${MONTHS[m - 1]} ${y}`;
};
/** 2026-11 → Nov 2026 */
export const niceMonth = (p: string) => `${MONTHS[Number(p.slice(5, 7)) - 1]} ${p.slice(0, 4)}`;
export const daysInMonth = (p: string) => new Date(Number(p.slice(0, 4)), Number(p.slice(5, 7)), 0).getDate();
export const weekday = (date: string) => new Date(date + 'T12:00:00').getDay();
export const time = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleTimeString('en-PK', { hour: '2-digit', minute: '2-digit' }) : '');

/** CNIC and B-Form share the NADRA format 12345-1234567-1. */
export const CNIC_RE = /^\d{5}-\d{7}-\d$/;
export const PHONE_RE = /^(\+92|0)3\d{9}$/;
export const normalizePhone = (v: string) => v.replace(/[\s-]/g, '');
export function formatCnic(v: string): string {
  const d = v.replace(/\D/g, '').slice(0, 13);
  if (d.length <= 5) return d;
  if (d.length <= 12) return `${d.slice(0, 5)}-${d.slice(5)}`;
  return `${d.slice(0, 5)}-${d.slice(5, 12)}-${d.slice(12)}`;
}
/** 03001234567 → 923001234567 for wa.me links */
export const waNumber = (phone: string) => normalizePhone(phone).replace(/^\+/, '').replace(/^0/, '92');
export const percent = (part: number, whole: number) => (whole > 0 ? Math.round((part / whole) * 100) : 0);
export const grade = (pct: number) => (pct >= 90 ? 'A+' : pct >= 80 ? 'A' : pct >= 70 ? 'B' : pct >= 60 ? 'C' : pct >= 50 ? 'D' : pct >= 33 ? 'E' : 'F');
