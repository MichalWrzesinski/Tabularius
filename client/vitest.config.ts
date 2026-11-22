import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      // Logic modules carry money, CSV and date rules; views are covered by E2E (docs/qa/critical-paths.md).
      include: ['src/lib/**/*.ts'],
      exclude: ['src/lib/useAsync.ts'],
      thresholds: { lines: 90, branches: 85, functions: 90, statements: 90 },
    },
  },
})
