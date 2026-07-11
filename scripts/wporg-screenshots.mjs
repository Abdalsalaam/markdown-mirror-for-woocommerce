#!/usr/bin/env node
/**
 * wporg-screenshots.mjs - captures the wp.org listing screenshots from the REAL
 * running wp-env site. Screenshots are actual plugin output over the seeded demo
 * catalog, never mockups.
 *
 * Prereqs:
 *   npx wp-env start
 *   npx wp-env run cli wp rewrite structure '/%postname%/' --hard
 *   npx wp-env run cli wp eval-file wp-content/plugins/product-markdown-mirror/scripts/wporg-screenshots-seed.php
 *
 * Run: node scripts/wporg-screenshots.mjs
 * Writes .wordpress.org/screenshot-{1,2,3}.png (keep readme.txt's Screenshots
 * section in sync with these).
 */
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.env.WP_BASE_URL || 'http://localhost:8890';
const OUT = join(resolve(dirname(fileURLToPath(import.meta.url)), '..'), '.wordpress.org');
const VIEWPORT = { width: 1280, height: 800 };

async function dismissNotices(page) {
  for (const btn of await page.locator('.notice-dismiss').all()) {
    try {
      await btn.click({ timeout: 1000 });
    } catch {
      /* already gone */
    }
  }
  await page.waitForTimeout(300);
}

/** Plain-text mirror pages render tiny in the browser default style; zoom for legibility. */
async function zoomText(page, factor) {
  await page.evaluate((f) => {
    document.body.style.zoom = String(f);
  }, factor);
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();

  // Admin context: the settings screen (taller viewport so the taxonomy
  // toggles below the content checkboxes stay in frame).
  const admin = await browser.newContext({
    viewport: { width: VIEWPORT.width, height: 960 },
    deviceScaleFactor: 2,
  });
  const adminPage = await admin.newPage();
  await adminPage.goto(`${BASE}/wp-login.php`);
  await adminPage.fill('#user_login', 'admin');
  await adminPage.fill('#user_pass', 'password');
  await adminPage.click('#wp-submit');
  await adminPage.waitForURL('**/wp-admin/**');

  await adminPage.goto(`${BASE}/wp-admin/admin.php?page=wc-settings&tab=products&section=markdown-mirror`);
  await adminPage.waitForSelector('#mainform', { timeout: 15000 });
  await dismissNotices(adminPage);
  await adminPage.screenshot({ path: join(OUT, 'screenshot-1.png'), animations: 'disabled', caret: 'hide' });
  console.log('wrote screenshot-1.png (settings section)');

  // Visitor context: the mirrors as any agent/visitor gets them (no login).
  const visitor = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: 2 });
  const page = await visitor.newPage();

  await page.goto(`${BASE}/product/ceramic-dripper.md`);
  await zoomText(page, 1.45);
  await page.screenshot({ path: join(OUT, 'screenshot-2.png') });
  console.log('wrote screenshot-2.png (product mirror)');

  // Term documents are short; clip to the content instead of shipping white space.
  await page.goto(`${BASE}/product-category/coffee-gear/drippers.md`);
  await zoomText(page, 1.45);
  await page.screenshot({
    path: join(OUT, 'screenshot-3.png'),
    clip: { x: 0, y: 0, width: VIEWPORT.width, height: 480 },
  });
  console.log('wrote screenshot-3.png (category term mirror)');

  await browser.close();
  console.log('wporg-screenshots: done.');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
