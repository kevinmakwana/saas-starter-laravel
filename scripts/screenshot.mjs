// Captures docs/screenshot.png from a running local app (make up).
// Usage: npx playwright install chromium && node scripts/screenshot.mjs
import { chromium } from 'playwright';

const base = process.env.APP_URL ?? 'http://localhost:8080';
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 });

await page.goto(`${base}/login`);
await page.fill('input[type=email]', 'demo@acme.test');
await page.fill('input[type=password]', 'password');
await Promise.all([page.waitForURL('**/dashboard'), page.click('form button')]);
await page.waitForLoadState('networkidle');

await page.screenshot({ path: 'docs/screenshot.png' });
await browser.close();
console.log('Saved docs/screenshot.png');
