import { defineConfig } from '@playwright/test';

/**
 * E2E contra el sitio de wp-env (npx wp-env start). Usuario admin / password.
 */
export default defineConfig( {
	testDir: 'tests/e2e',
	timeout: 60_000,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? 'github' : 'list',
	use: {
		baseURL: process.env.WP_BASE_URL ?? 'http://localhost:8888',
		trace: 'retain-on-failure',
	},
	projects: [ { name: 'chromium', use: { browserName: 'chromium' } } ],
} );
