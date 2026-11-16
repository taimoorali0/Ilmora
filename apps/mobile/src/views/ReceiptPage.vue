<template>
  <ion-page>
    <PageHeader title="Receipt" back="/fees" />
    <ion-content>
      <div class="wrap" v-if="payment && family">
        <div class="row acts no-print">
          <button class="btn" @click="printPage"><ion-icon :icon="printOutline" /> Print</button>
          <a v-if="office" class="btn wa" :href="waLink(family.whatsapp_phone || family.primary_phone, text)" target="_blank" rel="noopener"><ion-icon :icon="logoWhatsapp" /> Send on WhatsApp</a>
          <button v-if="office" class="btn" @click="$router.push(`/families/${family.id}`)">Open family</button>
          <button v-if="canReverse && !reversal" class="btn danger" @click="reverse">Reverse this payment</button>
        </div>
        <div v-if="reversal" class="notice warn no-print">This payment was reversed: {{ reversal.reason }}. It no longer counts towards the family balance.</div>
        <div class="sheet">
          <Letterhead />
          <h3 class="doc">Fee receipt{{ reversal ? ' (reversed)' : '' }}</h3>
          <div class="two">
            <dl class="kv"><dt>Received from</dt><dd>{{ family.primary_guardian_name }} ({{ family.code }})</dd><dt>Children</dt><dd>{{ children }}</dd></dl>
            <dl class="kv"><dt>Receipt no</dt><dd><b>{{ payment.receipt_no }}</b></dd><dt>Date</dt><dd>{{ nice(payment.received_at) }} {{ time(payment.received_at) }}</dd><dt>Method</dt><dd class="cap">{{ payment.method }}{{ payment.reference ? ' · ' + payment.reference : '' }}</dd></dl>
          </div>
          <div class="amount"><span>Amount received</span><b>{{ money(payment.amount) }}</b></div>
          <table class="tbl">
            <tbody>
              <tr><td>Total invoiced to date</td><td class="right num">{{ money(ledger.invoiced) }}</td></tr>
              <tr><td>Total paid to date</td><td class="right num">{{ money(ledger.paid) }}</td></tr>
              <tr><td><b>{{ ledger.balance < 0 ? 'Advance with school' : 'Balance remaining' }}</b></td><td class="right num"><b>{{ money(Math.abs(ledger.balance)) }}</b></td></tr>
            </tbody>
          </table>
          <div class="signs"><span>Received by: {{ receiver }}</span><span>Signature and stamp</span></div>
        </div>
      </div>
      <p v-else class="empty">This receipt is not on this device.</p>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { logoWhatsapp, printOutline } from 'ionicons/icons';
import { computed } from 'vue';
import { money, nice, time } from '@/lib/format';
import { familyLedger, reversePayment } from '@/lib/logic/fees';
import { waLink } from '@/lib/logic/messages';
import { canWrite, hasRole, session } from '@/lib/session';
import { all, find, groupBy, raw } from '@/lib/store';
import { ask, printPage, toast } from '@/lib/ui';

const props = defineProps<{ id: string }>();
const office = computed(() => hasRole('owner', 'principal', 'admin', 'accountant'));
const payment = computed(() => find('payments', props.id));
const family = computed(() => find('families', payment.value?.family_id));
const ledger = computed(() => familyLedger(payment.value?.family_id ?? ''));
const reversal = computed(() => all('reversals').find((r) => r.target_id === props.id));
const children = computed(() => (groupBy('students', 'family_id').get(family.value?.id) ?? []).filter((s) => s.status === 'active').map((s) => s.name).join(', '));
const receiver = computed(() => raw('users').find((u) => u.id === payment.value?.received_by)?.name ?? session.data?.user.name ?? '');
const canReverse = computed(() => hasRole('owner', 'accountant') && canWrite());
const text = computed(() => `Assalam-o-Alaikum ${family.value?.primary_guardian_name}, we received ${money(payment.value?.amount)} (receipt ${payment.value?.receipt_no}). ` +
  `${ledger.value.balance > 0 ? 'Balance remaining ' + money(ledger.value.balance) + '.' : 'Your account is clear.'} JazakAllah. – ${session.data?.user.school?.name ?? ''}`);

async function reverse() {
  if (!(await ask('Reverse this payment?', 'The receipt stays on record, marked as reversed, and the amount is added back to the family balance.', 'Reverse payment', true))) return;
  reversePayment(props.id, 'Entered by mistake');
  toast('Payment reversed.');
}
</script>

<style scoped>
.acts { margin-bottom: 14px; justify-content: center; }
.two { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px 24px; margin-bottom: 16px; }
.amount { display: flex; justify-content: space-between; align-items: center; background: #eef7f6; border-radius: 12px; padding: 14px 18px; margin-bottom: 14px; }
.amount b { font-size: 1.5rem; color: #071a3a; }
.cap { text-transform: capitalize; }
</style>
