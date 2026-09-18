// Takes the screenshots for resources/manual (the admin manual) against a running local site with
// the demo data loaded. Re-run after any admin screen changes.
//
//   php artisan db:seed --class=DemoDataSeeder
//   php artisan serve            (or the preview server on :8000)
//   node docs/tools/screenshots.mjs [http://localhost:8000]
//
// Signs in as the demo Super Admin. That account has no second factor
// after seeding (the first sign-in enrols); this script gives it a known
// TOTP secret through artisan first, so the sign-in is the real one.
// Light theme, 1280×800, so the pictures match a laptop in an office.

import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const base = (process.argv[2] || 'http://localhost:8000').replace(/\/$/, '');
const admin = `${base}/scghf-office`;
const out = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'resources', 'manual', 'images');
mkdirSync(out, { recursive: true });

const SECRET = 'JBSWY3DPEHPK3PXP';

// Give the demo super admin the known secret (idempotent).
execSync('php artisan tinker docs/tools/demo-totp.php', { stdio: 'inherit' });

function base32ToBuffer(s) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of s.replace(/=+$/, '')) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}

function totp(secret) {
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
    const h = createHmac('sha1', base32ToBuffer(secret)).update(counter).digest();
    const o = h[h.length - 1] & 0xf;
    const code = ((h[o] & 0x7f) << 24 | h[o + 1] << 16 | h[o + 2] << 8 | h[o + 3]) % 1_000_000;
    return String(code).padStart(6, '0');
}

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, colorScheme: 'light', deviceScaleFactor: 1 });
const page = await context.newPage();

async function shot(name, url, opts = {}) {
    await page.goto(url, { waitUntil: 'networkidle' });
    if (opts.before) await opts.before(page);
    await page.waitForTimeout(600);
    await page.screenshot({ path: join(out, `${name}.png`), fullPage: opts.fullPage ?? false });
    console.log(`  ${name}.png`);
}

// ── Sign in, with the second factor ──────────────────────────────────────
await page.goto(`${admin}/login`, { waitUntil: 'networkidle' });
await page.screenshot({ path: join(out, '01-sign-in.png') });
await page.fill('input[type="email"]', 'demo.superadmin@example.test');
await page.fill('input[type="password"]', 'password');
await page.click('button[type="submit"]');

// The second-factor challenge replaces the form in place (Livewire), on the
// same URL: wait for its heading, not for a navigation.
await page.waitForSelector('text=Verify your identity', { timeout: 30_000 });
await page.screenshot({ path: join(out, '02-two-factor-code.png') });
const codeField = page.locator('input[autocomplete="one-time-code"], input[inputmode="numeric"], input[type="text"]:visible').first();
await codeField.fill(totp(SECRET));
await page.click('button[type="submit"]');
await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 30_000 });
await page.waitForLoadState('networkidle');
console.log('signed in:', page.url());

// ── The screens the manual talks about ───────────────────────────────────
await shot('03-dashboard', admin);
await shot('04-profile-two-factor', `${admin}/profile`);
await shot('10-pages-list', `${admin}/pages`);
const firstPageEdit = await page.locator('a[href*="/scghf-office/pages/"][href$="/edit"]').first().getAttribute('href').catch(() => null);
if (firstPageEdit) await shot('11-page-edit-sections', firstPageEdit, { fullPage: true });
await shot('12-menus', `${admin}/menus`);
await shot('13-theme-colours', `${admin}/theme-settings`);
await shot('14-site-settings', `${admin}/manage-settings`);
await shot('20-projects', `${admin}/projects`);
await shot('21-causes', `${admin}/causes`);
await shot('22-posts', `${admin}/posts`);
await shot('23-post-create', `${admin}/posts/create`, { fullPage: true });
await shot('24-products', `${admin}/products`);
await shot('25-events', `${admin}/events`);
await shot('26-media-library', `${admin}/media`);
await shot('30-donations', `${admin}/donations`);
const firstDonation = await page.locator('table a[href*="/donations/"]').first().getAttribute('href').catch(() => null);
if (firstDonation) await shot('31-donation-view', firstDonation, { fullPage: true });
await shot('32-record-offline-gift', `${admin}/donations/create`, { fullPage: true });
await shot('33-giving-reports', `${admin}/giving-reports`);
await shot('34-donors', `${admin}/donors`);
await shot('40-orders', `${admin}/orders`);
const firstOrder = await page.locator('table a[href*="/orders/"]').first().getAttribute('href').catch(() => null);
if (firstOrder) await shot('41-order-view', firstOrder, { fullPage: true });
await shot('50-newsletter-campaigns', `${admin}/newsletter-campaigns`);
await shot('51-newsletter-campaign-create', `${admin}/newsletter-campaigns/create`, { fullPage: true });
await shot('52-sms-broadcasts', `${admin}/sms-broadcasts`);
await shot('53-sms-broadcast-create', `${admin}/sms-broadcasts/create`, { fullPage: true });
await shot('60-site-health', `${admin}/site-health`, { fullPage: true });
await shot('61-staff-accounts', `${admin}/users`);
await shot('62-analytics', `${admin}/analytics`);
await shot('63-help-page', `${admin}/help/05-donations`);

// ── The public site, both themes ─────────────────────────────────────────
await shot('70-public-home-light', `${base}/`);
await context.addCookies([{ name: 'scghf_theme', value: 'dark', url: base }]);
await shot('71-public-home-dark', `${base}/`);

await browser.close();
console.log('done →', out);
