<template>
  <ion-app>
    <ion-split-pane content-id="main" when="lg" :disabled="!showMenu">
      <ion-menu content-id="main" :disabled="!showMenu" type="overlay">
        <ion-content>
          <div class="brand">
            <img :src="mark" alt="" />
            <div><b>Ilmora</b><span>{{ user?.school?.name }}</span></div>
          </div>
          <nav aria-label="Modules">
            <template v-for="g in groups" :key="g.name">
              <p v-if="g.name" class="group">{{ g.name }}</p>
              <ion-menu-toggle v-for="n in g.items" :key="n.path" :auto-hide="false">
                <button class="nav" :class="{ on: isOn(n.path) }" @click="go(n.path)">
                  <ion-icon :icon="n.icon" aria-hidden="true" /> {{ n.label }}
                </button>
              </ion-menu-toggle>
            </template>
          </nav>
          <div class="me">
            <div class="avatar">{{ initials(user?.name ?? '') }}</div>
            <div class="grow"><b>{{ user?.name }}</b><span>{{ roles }}</span></div>
          </div>
          <button class="nav out" @click="signOut"><ion-icon :icon="logOutOutline" aria-hidden="true" /> Sign out</button>
        </ion-content>
      </ion-menu>
      <ion-router-outlet id="main" />
    </ion-split-pane>

    <div class="toasts" aria-live="polite">
      <div v-for="t in ui.toasts" :key="t.id" class="toast" :class="t.kind">{{ t.text }}</div>
    </div>
    <AppModal :open="!!ui.confirm" :title="ui.confirm?.title ?? ''" @close="answer(false)">
      <p class="msg">{{ ui.confirm?.message }}</p>
      <div class="actions">
        <button class="btn" @click="answer(false)">Cancel</button>
        <button class="btn primary" :class="{ red: ui.confirm?.danger }" @click="answer(true)">{{ ui.confirm?.okText }}</button>
      </div>
    </AppModal>
  </ion-app>
</template>

<script setup lang="ts">
import { logOutOutline } from 'ionicons/icons';
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import mark from '@/assets/ilmora-mark.png';
import AppModal from '@/components/AppModal.vue';
import { logout } from '@/lib/auth';
import { myNav } from '@/lib/nav';
import { initials, roleLabel } from '@/lib/school';
import { hasRole, session } from '@/lib/session';
import { answer, ask, ui } from '@/lib/ui';

const route = useRoute();
const router = useRouter();
const user = computed(() => session.data?.user);
const roles = computed(() => user.value?.roles.map((r) => roleLabel(r.role)).join(', ') ?? '');
const staffSide = computed(() => !!user.value && !user.value.super && hasRole('owner', 'principal', 'admin', 'accountant', 'teacher', 'examiner'));
const showMenu = computed(() => staffSide.value && route.path !== '/login');
const groups = computed(() => {
  const out: { name: string; items: ReturnType<typeof myNav> }[] = [];
  for (const n of myNav()) {
    const g = out.find((x) => x.name === n.group);
    if (g) g.items.push(n); else out.push({ name: n.group, items: [n] });
  }
  return out;
});
const isOn = (path: string) => route.path === path || (path !== '/home' && path !== '/attendance' && route.path.startsWith(path + '/'));
const go = (path: string) => { if (route.path !== path) router.replace(path); };

async function signOut() {
  if (await ask('Sign out?', 'You will need your login and password to open the school again.', 'Sign out')) { await logout(); router.replace('/login'); }
}
</script>

<style scoped>
ion-menu { --width: 264px; }
ion-menu ion-content { --background: var(--ilm-navy); --color: #fff; }
.brand { display: flex; gap: 10px; align-items: center; padding: 18px 16px 12px; color: #fff; }
.brand img { width: 34px; height: 34px; border-radius: 8px; background: #fff; padding: 3px; }
.brand b { display: block; font-size: 1.05rem; }
.brand span { font-size: 0.75rem; opacity: 0.75; }
.group { margin: 14px 16px 4px; font-size: 0.7rem; letter-spacing: 0.08em; text-transform: uppercase; color: #8fa3c4; }
.nav { appearance: none; border: 0; background: transparent; color: #d7e0ee; width: calc(100% - 16px); margin: 1px 8px; text-align: left; font: inherit; font-size: 0.9rem; padding: 9px 10px; min-height: 40px; border-radius: 10px; display: flex; gap: 10px; align-items: center; cursor: pointer; }
.nav ion-icon { font-size: 1.15rem; flex: none; }
.nav:hover { background: rgba(255, 255, 255, 0.08); }
.nav.on { background: var(--ilm-teal); color: #fff; font-weight: 600; }
.nav:focus-visible { outline: 2px solid #fff; }
.me { display: flex; gap: 10px; align-items: center; margin: 18px 16px 6px; padding-top: 14px; border-top: 1px solid rgba(255, 255, 255, 0.14); color: #fff; }
.me b { display: block; font-size: 0.88rem; }
.me span { font-size: 0.75rem; opacity: 0.75; }
.out { margin-bottom: 20px; }
.toasts { position: fixed; left: 0; right: 0; bottom: 18px; z-index: 40000; display: flex; flex-direction: column; align-items: center; gap: 8px; pointer-events: none; padding: 0 12px; }
.toast { background: #071a3a; color: #fff; padding: 10px 16px; border-radius: 12px; font-size: 0.9rem; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3); max-width: 520px; }
.toast.bad { background: #b3261e; }
.toast.info { background: #2f4bb8; }
.msg { margin: 0; line-height: 1.5; }
.btn.red { background: #b3261e; border-color: #b3261e; }
</style>
