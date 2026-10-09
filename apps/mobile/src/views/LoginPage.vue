<template>
  <ion-page>
    <ion-content class="ion-padding">
      <div class="wrap">
        <img src="/ilmora-logo-full.png" alt="Ilmora" class="logo" />
        <p class="ilm-muted tagline">Paperless school management, online and offline</p>

        <form @submit.prevent="submit">
          <ion-list lines="full" class="form">
            <ion-item>
              <ion-input v-model="school" label="School code" label-placement="stacked" placeholder="e.g. demo" autocapitalize="off" required />
            </ion-item>
            <ion-item>
              <ion-input v-model="login" label="Phone or email" label-placement="stacked" inputmode="tel" autocomplete="username" required />
            </ion-item>
            <ion-item>
              <ion-input v-model="password" type="password" label="Password" label-placement="stacked" autocomplete="current-password" required />
            </ion-item>
          </ion-list>

          <ion-text v-if="error" color="danger"><p role="alert">{{ error }}</p></ion-text>

          <ion-button expand="block" type="submit" :disabled="busy" class="ion-margin-top">
            <ion-spinner v-if="busy" name="dots" /> <span v-else>Sign in</span>
          </ion-button>
          <p class="ilm-muted center">First sign-in on a device needs internet.</p>
        </form>
      </div>
    </ion-content>
  </ion-page>
</template>

<script setup lang="ts">
import { IonButton, IonContent, IonInput, IonItem, IonList, IonPage, IonSpinner, IonText, useIonRouter } from '@ionic/vue';
import { Capacitor } from '@capacitor/core';
import { ref } from 'vue';
import { api, ApiError } from '@/lib/api';
import { saveSession, type SessionData } from '@/lib/session';
import { connectSync } from '@/powersync/db';
import { startHeartbeat } from '@/lib/heartbeat';

const school = ref('');
const login = ref('');
const password = ref('');
const busy = ref(false);
const error = ref('');
const router = useIonRouter();

async function submit() {
  busy.value = true;
  error.value = '';
  try {
    const res = await api<Omit<SessionData, 'access'>>('/auth/login', {
      method: 'POST',
      json: {
        school: school.value.trim().toLowerCase(),
        login: login.value.trim(),
        password: password.value,
        platform: Capacitor.getPlatform(),
        device_name: navigator.userAgent.slice(0, 80),
      },
    });
    await saveSession({ token: res.token, user: res.user, device: res.device, access: 'full' });
    await connectSync();
    startHeartbeat();
    router.replace('/home');
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Cannot reach the server. Check your internet connection.';
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped>
.wrap { max-width: 420px; margin: 8vh auto 0; }
.logo { width: 220px; display: block; margin: 0 auto; }
.tagline { text-align: center; margin: 8px 0 28px; }
.form { border-radius: 12px; overflow: hidden; }
.center { text-align: center; }
</style>
