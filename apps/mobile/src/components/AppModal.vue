<template>
  <Teleport to="body">
    <div v-if="open" class="veil" @mousedown.self="$emit('close')">
      <div class="dialog" role="dialog" aria-modal="true" :aria-label="title" :class="{ wide }">
        <header>
          <h3>{{ title }}</h3>
          <button class="x" aria-label="Close" @click="$emit('close')"><ion-icon :icon="close" /></button>
        </header>
        <div class="body"><slot /></div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { close } from 'ionicons/icons';
import { onMounted, onUnmounted } from 'vue';

const props = defineProps<{ open: boolean; title: string; wide?: boolean }>();
const emit = defineEmits<{ close: [] }>();
const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && props.open) emit('close'); };
onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<style scoped>
.veil { position: fixed; inset: 0; z-index: 30000; background: rgba(7, 26, 58, 0.55); display: grid; place-items: center; padding: 12px; }
.dialog { background: var(--ilm-card); color: var(--ion-text-color); border-radius: 16px; width: min(560px, 100%); max-height: calc(100vh - 24px); max-height: calc(100dvh - 24px); display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35); }
.dialog.wide { width: min(820px, 100%); }
header { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--ilm-line); }
h3 { margin: 0; font-size: 1.05rem; }
.x { appearance: none; border: 0; background: transparent; color: inherit; width: 40px; height: 40px; font-size: 1.4rem; display: grid; place-items: center; cursor: pointer; border-radius: 50%; }
.body { padding: 16px; overflow-y: auto; }
</style>
