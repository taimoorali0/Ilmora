<template>
  <ion-page>
    <PageHeader title="Fee voucher" back="/fees" />
    <ion-content>
      <div class="wrap" v-if="invoice && family">
        <div class="row acts no-print">
          <button class="btn" @click="printPage"><ion-icon :icon="printOutline" /> Print</button>
          <a v-if="office" class="btn wa" :href="waLink(family.whatsapp_phone || family.primary_phone, text)" target="_blank" rel="noopener" @click="log"><ion-icon :icon="logoWhatsapp" /> Send on WhatsApp</a>
          <button v-if="office" class="btn" @click="$router.push(`/families/${family.id}`)">Open family</button>
        </div>
        <div class="sheet">
          <Letterhead />
          <h3 class="doc">Fee voucher · {{ niceMonth(invoice.period) }}</h3>
          <div class="two">
            <dl class="kv"><dt>Family</dt><dd>{{ family.primary_guardian_name }} ({{ family.code }})</dd><dt>Phone</dt><dd>{{ family.primary_phone }}</dd><dt>Children</dt><dd>{{ children }}</dd></dl>
            <dl class="kv"><dt>Voucher no</dt><dd>{{ invoice.number }}</dd><dt>Issue date</dt><dd>{{ nice(invoice.issue_date) }}</dd><dt>Due date</dt><dd><b>{{ nice(invoice.due_date) }}</b></dd></dl>
          </div>
          <table class="tbl">
            <thead><tr><th>Description</th><th class="right">Amount</th></tr></thead>
            <tbody>
              <tr v-for="l in lines" :key="l.id"><td>{{ l.description }}</td><td class="right num">{{ money(l.amount) }}</td></tr>
            </tbody>
            <tfoot>
              <tr><td>This voucher</td><td class="right num">{{ money(state?.total ?? 0) }}</td></tr>
              <tr v-if="arrears > 0"><td>Previous dues</td><td class="right num">{{ money(arrears) }}</td></tr>
              <tr v-if="(state?.paid ?? 0) > 0"><td>Paid against this voucher</td><td class="right num">− {{ money(state?.paid ?? 0) }}</td></tr>
              <tr><td>Payable now</td><td class="right num">{{ money(payable) }}</td></tr>
            </tfoot>
          </table>
          <p class="muted" style="margin-top:14px">Please pay by the due date. A late fee is added to the next voucher when dues remain unpaid.</p>
          <div class="signs"><span>Accounts office</span><span>Parent / depositor</span></div>
        </div>
      </div>
      <p v-else class="empty">This voucher is not on this device.</p>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { logoWhatsapp, printOutline } from 'ionicons/icons';
import { computed } from 'vue';
import { money, nice, niceMonth } from '@/lib/format';
import { familyLedger } from '@/lib/logic/fees';
import { queueMessage, templates, waLink } from '@/lib/logic/messages';
import { hasRole } from '@/lib/session';
import { find, groupBy } from '@/lib/store';
import { printPage } from '@/lib/ui';

const props = defineProps<{ id: string }>();
const office = computed(() => hasRole('owner', 'principal', 'admin', 'accountant'));
const invoice = computed(() => find('invoices', props.id));
const family = computed(() => find('families', invoice.value?.family_id));
const lines = computed(() => groupBy('invoice_lines', 'invoice_id').get(props.id) ?? []);
const ledger = computed(() => familyLedger(invoice.value?.family_id ?? ''));
const state = computed(() => ledger.value.invoices.find((s) => s.invoice.id === props.id));
const arrears = computed(() => ledger.value.invoices.filter((s) => s.invoice.period < (invoice.value?.period ?? '')).reduce((t, s) => t + s.due, 0));
const payable = computed(() => (state.value?.due ?? 0) + arrears.value);
const children = computed(() => (groupBy('students', 'family_id').get(family.value?.id) ?? []).filter((s) => s.status === 'active').map((s) => s.name).join(', '));
const text = computed(() => (family.value && invoice.value ? templates.voucher(family.value, invoice.value.period, payable.value, invoice.value.due_date) : ''));
function log() { if (family.value) queueMessage(family.value.id, 'fee_voucher', text.value); }
</script>

<style scoped>
.acts { margin-bottom: 14px; justify-content: center; }
.two { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px 24px; margin-bottom: 16px; }
</style>
