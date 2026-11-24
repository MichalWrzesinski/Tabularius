import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  // Hidden source maps (TASK-015): scripts do not reference them; the image keeps them outside the web root for Sentry.
  build: { sourcemap: 'hidden' },
  server: { port: 5173, strictPort: true, allowedHosts: ['host.docker.internal'] },
})
