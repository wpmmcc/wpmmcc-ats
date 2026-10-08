import { defineConfig } from '@playwright/test'
import owned from './playwright.client-local-ui.config'

export default defineConfig({
  ...owned,
  testDir: './client-ui-setup',
  testMatch: ['review-mode.local.spec.ts'],
  timeout: 180_000,
  use: { ...owned.use, actionTimeout: 20_000 },
})
