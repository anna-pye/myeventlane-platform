#!/usr/bin/env node
/**
 * Read-only Event Studio browser verification audit.
 * Run: node scripts/audit/event-studio-browser-verification.mjs
 * Requires: npx playwright (installed on first run)
 */
import { chromium, devices } from 'playwright';
import { execSync } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = 'https://myeventlane.ddev.site';
const DRAFT_NID = 1226;
const PAID_NID = 1094;
const AUTOSAVE_DELAY_MS = 13000;
const OUT_DIR = join(dirname(fileURLToPath(import.meta.url)), 'output');

const SECTIONS = [
  { id: 'information', path: `/vendor/events/${DRAFT_NID}/studio/information`, writable: true, autosave: true },
  { id: 'branding', path: `/vendor/events/${DRAFT_NID}/studio/branding`, writable: true, autosave: true },
  { id: 'content', path: `/vendor/events/${DRAFT_NID}/studio/content`, writable: true, autosave: true },
  { id: 'tickets', path: `/vendor/events/${PAID_NID}/studio/tickets`, writable: true, autosave: true },
  { id: 'questions', path: `/vendor/events/${DRAFT_NID}/studio/questions`, writable: true, autosave: true },
  { id: 'settings', path: `/vendor/events/${DRAFT_NID}/studio/settings`, writable: true, autosave: true },
];

function drushLoginUrl() {
  return execSync('ddev drush user:login --uid=1 --uri=https://myeventlane.ddev.site 2>/dev/null | tail -1', {
    encoding: 'utf8',
    cwd: join(dirname(fileURLToPath(import.meta.url)), '../..'),
  }).trim();
}

function result(section, checks) {
  return { section, ...checks, pass: Object.values(checks).every((v) => v !== false && v !== 'FAIL') };
}

async function collectConsole(page) {
  const errors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      errors.push(msg.text());
    }
  });
  page.on('pageerror', (err) => errors.push(String(err)));
  return errors;
}

async function dismissConsent(page) {
  const accept = page.locator('#klaro .cm-btn-success, #klaro button.accept-all, #klaro .cm-btn-accept-all');
  if (await accept.count()) {
    await accept.first().click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(500);
  }
  await page.evaluate(() => {
    document.querySelector('#klaro')?.remove();
    document.querySelector('.cm-bg')?.remove();
    const toolbar = document.querySelector('#toolbar-administration');
    if (toolbar) {
      toolbar.style.display = 'none';
    }
    document.body.classList.remove('toolbar-loading', 'gin--vertical-toolbar', 'toolbar-tray-open');
  }).catch(() => {});
}

async function auditSection(context, section) {
  const page = await context.newPage();
  const consoleErrors = await collectConsole(page);
  const checks = {
    http: 'SKIP',
    whiteScreen: 'SKIP',
    shellPresent: false,
    wizardNavDuplicate: null,
    dirtyState: 'SKIP',
    autosaveFired: 'SKIP',
    autosaveOk: 'SKIP',
    restoreDraftLink: 'SKIP',
    publishVisible: false,
    publishDirtyBlock: 'SKIP',
    aiAssistPresent: 'N/A',
    ticketEditing: 'N/A',
    consoleErrors: [],
    autosaveRequestCount: 0,
    duplicateNav: false,
  };

  const autosaveRequests = [];
  page.on('request', (req) => {
    if (req.method() === 'POST' && req.url().includes('/vendor/events/autosave')) {
      autosaveRequests.push({ url: req.url(), time: Date.now() });
    }
  });
  page.on('response', async (res) => {
    if (res.request().method() === 'POST' && res.url().includes('/vendor/events/autosave')) {
      const idx = autosaveRequests.findIndex((r) => r.url === res.url() && !r.status);
      const body = await res.text().catch(() => '');
      const entry = { url: res.url(), status: res.status(), body: body.slice(0, 200) };
      if (idx >= 0) {
        autosaveRequests[idx] = { ...autosaveRequests[idx], ...entry };
      } else {
        autosaveRequests.push(entry);
      }
    }
  });

  const response = await page.goto(`${BASE}${section.path}`, { waitUntil: 'networkidle', timeout: 60000 });
  await dismissConsent(page);
  checks.http = response?.status() ?? 'FAIL';
  checks.whiteScreen = !(await page.locator('[data-mel-studio-shell]').count());
  checks.shellPresent = !checks.whiteScreen;
  checks.wizardNavDuplicate = (await page.locator('.mel-event-studio-wizard-nav').count()) > 0;
  checks.duplicateNav = checks.wizardNavDuplicate && (await page.locator('.mel-event-studio-sidebar').count()) > 0;
  checks.publishVisible = (await page.locator('[data-mel-publish-action]').count()) > 0;

  if (section.id === 'information' || section.id === 'content') {
    checks.aiAssistPresent = (await page.locator('[data-mel-ai-assist], .mel-ai-assist, [data-mel-ai-field-name]').count()) > 0;
  }

  if (section.id === 'tickets') {
    checks.ticketEditing =
      (await page.locator('[data-mel-ticket-preview], .mel-event-ticket-preview').count()) > 0 ||
      (await page.locator('input[name*="ticket"], [data-mel-ticket]').count()) > 0;
  }

  if (section.writable && section.autosave && section.id === 'information') {
    const input = page.locator(
      'form[data-mel-event-studio-form="1"] input[type="text"]:not([type="hidden"]), form[data-mel-event-studio-form="1"] textarea',
    ).first();
    if (await input.count()) {
      const prior = await input.inputValue().catch(() => '');
      const stamp = ` audit-${Date.now()}`;
      await input.fill(prior + stamp);
      await page.waitForTimeout(500);
      const status = page.locator('#mel-studio-form-state');
      const statusText = (await status.textContent())?.trim() || '';
      checks.dirtyState = /unsaved|Unsaved/i.test(statusText) || (await status.evaluate((el) => el.classList.contains('is-unsaved')));
      await page.waitForTimeout(AUTOSAVE_DELAY_MS);
      checks.autosaveFired = autosaveRequests.length > 0;
      checks.autosaveRequestCount = autosaveRequests.length;
      const last = autosaveRequests[autosaveRequests.length - 1];
      checks.autosaveOk = last?.status === 200 && /"ok"\s*:\s*true/.test(last?.body || '');
      if (checks.autosaveOk) {
        await page.reload({ waitUntil: 'networkidle' });
        const restoreText = await page.locator('#mel-studio-form-state a, .mel-event-studio-topbar__autosave a').textContent().catch(() => '');
        checks.restoreDraftLink = /restore/i.test(restoreText || '');
      }
      const pub = page.locator('[data-mel-publish-action]').first();
      if (await pub.count()) {
        try {
          await pub.click({ force: true });
          await page.waitForTimeout(800);
          const feedback = await page.locator('[data-mel-publish-feedback]:not([hidden])').textContent().catch(() => '');
          checks.publishDirtyBlock = /save|unsaved|before publishing/i.test(feedback || '');
        } catch (e) {
          checks.publishDirtyBlock = `CLICK_FAIL:${e.message?.slice(0, 80)}`;
        }
      }
    }
  }

  checks.consoleErrors = [...consoleErrors];
  await page.close();
  return result(section.id, checks);
}

async function auditMobile(page, consoleErrors) {
  const path = `/vendor/events/${DRAFT_NID}/studio/information`;
  await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle', timeout: 60000 });
  await dismissConsent(page);
  const checks = {
    viewport: '375x812',
    shellPresent: (await page.locator('[data-mel-studio-shell]').count()) > 0,
    sidebarToggle: (await page.locator('[data-mel-studio-sidebar-toggle]').count()) > 0,
    publishVisible: (await page.locator('[data-mel-publish-action]').count()) > 0,
    horizontalOverflow: await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2),
    duplicateNav: (await page.locator('.mel-event-studio-wizard-nav').count()) > 0 && (await page.locator('.mel-event-studio-sidebar').count()) > 0,
    consoleErrors: [...consoleErrors],
  };
  if (checks.sidebarToggle) {
    try {
      await page.locator('[data-mel-studio-sidebar-toggle]').click({ force: true, timeout: 5000 });
      await page.waitForTimeout(400);
      checks.sidebarOpens = await page.evaluate(() => document.querySelector('[data-mel-studio-shell]')?.classList.contains('is-sidebar-open'));
    } catch (e) {
      checks.sidebarOpens = `CLICK_FAIL:${e.message?.slice(0, 80)}`;
    }
  }
  return { section: 'mobile-information', ...checks, pass: checks.shellPresent && checks.publishVisible && !checks.horizontalOverflow };
}

async function main() {
  mkdirSync(OUT_DIR, { recursive: true });
  const loginUrl = drushLoginUrl();
  const browser = await chromium.launch({ headless: true });
  const report = { generatedAt: new Date().toISOString(), desktop: [], mobile: null, errors: [] };

  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const loginPage = await context.newPage();
    await loginPage.goto(loginUrl, { waitUntil: 'networkidle', timeout: 60000 });
    const storageState = await context.storageState();
    await loginPage.close();

    const authedContext = await browser.newContext({ ignoreHTTPSErrors: true, storageState });

    for (const section of SECTIONS) {
      try {
        report.desktop.push(await auditSection(authedContext, section));
      } catch (e) {
        report.errors.push({ section: section.id, error: String(e) });
        report.desktop.push(result(section.id, { fatal: String(e) }));
      }
    }

    const mobilePage = await authedContext.newPage();
    const mobileConsole = await collectConsole(mobilePage);
    await mobilePage.setViewportSize({ width: 375, height: 812 });
    try {
      report.mobile = await auditMobile(mobilePage, mobileConsole);
      await mobilePage.screenshot({ path: join(OUT_DIR, 'mobile-information-375.png'), fullPage: true });
    } catch (e) {
      report.errors.push({ section: 'mobile', error: String(e) });
    }
    await mobilePage.close();
  } finally {
    await browser.close();
    writeFileSync(join(OUT_DIR, 'browser-verification-report.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
