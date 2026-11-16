import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { viteSingleFile } from 'vite-plugin-singlefile';
import { fileURLToPath, URL } from 'node:url';

/** The hosted demo is one file: drop the favicon link (it would point outside the file) and name the page. */
const demoPage = () => ({
  name: 'ilmora-demo-page',
  transformIndexHtml: (html: string) => html.replace(/^<!doctype html>/i, '<!DOCTYPE html>').replace(/\s*<link rel="icon"[^>]*>/, '').replace(/<title>[^<]*<\/title>/, '<title>Ilmora demo</title>'),
});

// `--mode demo` builds the self-contained demo: one HTML file, sample data, no server.
export default defineConfig(({ mode }) => ({
  plugins: [vue(), ...(mode === 'demo' ? [viteSingleFile(), demoPage()] : [])],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  base: mode === 'demo' ? './' : '/',
  publicDir: mode === 'demo' ? false : 'public',
  build: { outDir: mode === 'demo' ? 'dist-demo' : 'dist', chunkSizeWarningLimit: 4000 },
  server: { port: 5173 },
}));
