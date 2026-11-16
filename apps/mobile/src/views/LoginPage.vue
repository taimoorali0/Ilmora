<template>
  <ion-page>
    <ion-content>
      <div class="wrap login">
        <img :src="logo" alt="Ilmora" class="logo" />
        <p class="muted center tag">Paperless school management, online and offline</p>

        <div class="card">
          <div class="seg full" role="tablist">
            <button :class="{ on: mode === 'school' }" @click="mode = 'school'">School login</button>
            <button :class="{ on: mode === 'platform' }" @click="mode = 'platform'">Super admin</button>
          </div>

          <form @submit.prevent="submit" class="fields">
            <template v-if="mode === 'school'">
              <label class="field"><span>School code</span><input v-model="school" placeholder="e.g. demo" autocapitalize="off" autocomplete="organization" required /></label>
              <label class="field"><span>Phone number</span><input v-model="loginId" inputmode="tel" autocomplete="username" placeholder="03001234567" required /></label>
            </template>
            <label v-else class="field"><span>Email</span><input v-model="email" type="email" autocomplete="username" required /></label>
            <label class="field"><span>Password</span><input v-model="password" type="password" autocomplete="current-password" required /></label>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <button class="btn primary block" type="submit" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
          </form>
          <p v-if="!IS_DEMO" class="muted center">The first sign-in on a device needs internet. After that the app works offline.</p>
        </div>

        <div v-if="IS_DEMO" class="card">
          <h3>Demo logins</h3>
          <p class="muted">Tap a role to sign in. School code <b>demo</b>, password <b>password</b>.</p>
          <div class="list demo">
            <button v-for="d in DEMO_LOGINS" :key="d.login" class="item click" @click="quick(d.login)">
              <span class="avatar">{{ d.role[0] }}</span>
              <span class="grow"><b>{{ d.role }}</b> · {{ d.name }}<small>{{ d.note }}</small></span>
              <span class="muted num">{{ d.login }}</span>
            </button>
            <button class="item click" @click="quickSuper">
              <span class="avatar sa">S</span>
              <span class="grow"><b>Super admin</b> · Ilmora platform<small>Create schools, plans, suspend or activate</small></span>
              <span class="muted">{{ DEMO_SUPER.email }}</span>
            </button>
          </div>
          <button class="btn sm reset" @click="reset">Reset demo data</button>
        </div>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import logo from '@/assets/ilmora-logo-full.png';
import { login, loginPlatform } from '@/lib/auth';
import { DEMO_LOGINS, DEMO_PASSWORD, DEMO_SUPER, seedDemo } from '@/lib/demo';
import { IS_DEMO } from '@/lib/session';
import { ask, toast } from '@/lib/ui';
import { homeFor } from '@/router';

const router = useRouter();
const mode = ref<'school' | 'platform'>('school');
const school = ref(IS_DEMO ? 'demo' : '');
const loginId = ref('');
const email = ref('');
const password = ref('');
const busy = ref(false);
const error = ref('');

async function submit() {
  busy.value = true;
  error.value = '';
  try {
    if (mode.value === 'platform') await loginPlatform(email.value, password.value);
    else await login(school.value, loginId.value, password.value);
    password.value = '';
    router.replace(homeFor());
  } catch (e) {
    error.value = (e as Error).message || 'Cannot reach the server. Check your internet connection.';
  } finally {
    busy.value = false;
  }
}

function quick(phone: string) {
  mode.value = 'school'; school.value = 'demo'; loginId.value = phone; password.value = DEMO_PASSWORD;
  submit();
}
function quickSuper() {
  mode.value = 'platform'; email.value = DEMO_SUPER.email; password.value = DEMO_SUPER.password;
  submit();
}
async function reset() {
  if (!(await ask('Reset demo data?', 'Everything you added in this demo is removed and the sample school is restored.', 'Reset'))) return;
  seedDemo();
  toast('Demo data restored.');
}
</script>

<style scoped>
.login { max-width: 460px; padding-top: 6vh; }
.logo { width: 200px; display: block; margin: 0 auto; background: #fff; border-radius: 14px; padding: 10px 14px; }
.tag { margin: 10px 0 20px; }
.seg.full { display: flex; margin-bottom: 14px; }
.seg.full button { flex: 1; }
.fields { display: flex; flex-direction: column; gap: 12px; }
.demo { margin: 8px -16px 0; }
.demo .item { appearance: none; background: transparent; border: 0; border-bottom: 1px solid var(--ilm-line); font: inherit; color: inherit; text-align: left; width: 100%; font-size: 0.9rem; }
.demo small { display: block; color: var(--ilm-muted); font-size: 0.78rem; }
.avatar.sa { background: var(--ilm-warn-bg); color: var(--ilm-warn); }
.reset { margin-top: 12px; }
</style>
