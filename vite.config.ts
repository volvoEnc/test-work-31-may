import { defineConfig } from 'vitest/config';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

declare const process: {
  env: Record<string, string | undefined>;
};

const runtimeEnv = process.env;
const viteDevHost = runtimeEnv.VITE_DEV_HOST ?? '127.0.0.1';
const viteDevPort = Number(runtimeEnv.VITE_DEV_PORT ?? 5173);
const appHttpPort = runtimeEnv.APP_HTTP_PORT ?? '8088';
const appOrigins = [`http://127.0.0.1:${appHttpPort}`, `http://localhost:${appHttpPort}`];

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.ts'],
      refresh: true,
    }),
    vue(),
  ],
  server: {
    host: '0.0.0.0',
    port: viteDevPort,
    strictPort: true,
    origin: `http://${viteDevHost}:${viteDevPort}`,
    hmr: {
      host: viteDevHost,
      port: viteDevPort,
    },
    cors: {
      origin: appOrigins,
    },
    proxy: {
      '/api': {
        target: 'http://nginx',
        changeOrigin: true,
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['resources/js/test/setup.ts'],
  },
});
