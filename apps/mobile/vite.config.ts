import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
  plugins: [vue()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  // PowerSync ships web workers + WASM; Vite must not pre-bundle it.
  optimizeDeps: { exclude: ['@powersync/web'] },
  worker: { format: 'es' },
  server: { port: 5173 },
});
