import { E2E_API_PORT } from '../../scripts/e2e-api.mjs';

/**
 * Host names the test browser maps to this machine (playwright.config.ts): the portal and the API share one site, as
 * customer.bhabaghure.com.bd and api.bhabaghure.com.bd do in production, so the SameSite=Strict refresh cookie travels.
 */
export const PORT = 3100;
export const SITE_URL = `http://localhost:${PORT}`;
export const TEST_DOMAIN = 'e2e.example.com';
export const PORTAL_URL = `http://customer.${TEST_DOMAIN}:${PORT}`;
export const BROWSER_API_URL = `http://api.${TEST_DOMAIN}:${E2E_API_PORT}`;
