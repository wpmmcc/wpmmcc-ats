import config from './playwright.client-local-ui.config';
import { defineConfig } from '@playwright/test';

if (!process.env.WPTSALL_OWNED_WP_CONTEXT) throw new Error('owned WordPress context missing');
export default defineConfig({ ...config, testDir: './owned-wp', retries: 0 });
