import { money, nice, niceMonth, waNumber } from '../format';
import { IS_DEMO, session } from '../session';
import { find, insert, type Row } from '../store';
import { familyLedger } from './fees';

/**
 * Parent messages (WhatsApp / SMS). The device queues a row; the server job sends it
 * through the configured gateway. Every message can also be opened in WhatsApp by hand.
 */
const school = () => session.data?.user.school?.name ?? 'School';

export const templates = {
  lateFee: (family: Row) => {
    const l = familyLedger(family.id);
    return `Assalam-o-Alaikum ${family.primary_guardian_name}, fee of ${money(l.balance)} is pending for your children at ${school()}. ` +
      `Please pay at the earliest to avoid late fee. JazakAllah.`;
  },
  voucher: (family: Row, periodYm: string, amount: number, dueDate: string) =>
    `Assalam-o-Alaikum ${family.primary_guardian_name}, the fee voucher for ${niceMonth(periodYm)} is ${money(amount)}, due ${nice(dueDate)}. – ${school()}`,
  absent: (family: Row, student: Row, date: string) =>
    `Assalam-o-Alaikum ${family.primary_guardian_name}, ${student.name} was absent from school on ${nice(date)}. Please inform the class teacher. – ${school()}`,
  attendance: (family: Row, student: Row, periodYm: string, present: number, absent: number, leave: number) =>
    `Assalam-o-Alaikum ${family.primary_guardian_name}, attendance of ${student.name} for ${niceMonth(periodYm)}: present ${present}, absent ${absent}, leave ${leave}. – ${school()}`,
  result: (family: Row, student: Row, exam: string, obtained: number, total: number, pct: number) =>
    `Assalam-o-Alaikum ${family.primary_guardian_name}, ${student.name} scored ${obtained}/${total} (${pct}%) in ${exam}. – ${school()}`,
  homework: (family: Row, title: string, due: string | null) =>
    `Assalam-o-Alaikum ${family.primary_guardian_name}, new homework: ${title}${due ? `, due ${nice(due)}` : ''}. – ${school()}`,
};

export function queueMessage(familyId: string | null, kind: string, body: string, channel: 'whatsapp' | 'sms' = 'whatsapp'): Row | null {
  const family = find('families', familyId);
  const phone = family?.whatsapp_phone || family?.primary_phone;
  if (!phone) return null;
  return insert('messages', {
    family_id: familyId, to_name: family?.primary_guardian_name ?? null, to_phone: phone, channel, kind, body,
    // The demo has no gateway: it only records the message.
    status: IS_DEMO ? 'sent' : 'queued', sent_at: IS_DEMO ? new Date().toISOString() : null, error: null,
  });
}

export const waLink = (phone: string, body: string) => `https://wa.me/${waNumber(phone)}?text=${encodeURIComponent(body)}`;
