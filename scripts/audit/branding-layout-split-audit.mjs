#!/usr/bin/env node
/**
 * Branding Studio desktop split layout audit — screenshots + measurements.
 * Run: node scripts/audit/branding-layout-split-audit.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT_DIR = join(dirname(fileURLToPath(import.meta.url)), 'output');
const BASE = 'https://myeventlane.ddev.site';
const NID = 1381;

function drush(cmd) {
  return execSync(`ddev drush ${cmd}`, { encoding: 'utf8', cwd: ROOT }).trim();
}

function drushLoginUrl() {
  return drush('user:login --uid=1 --uri=https://myeventlane.ddev.site').split('\n').pop();
}

async function dismissChrome(page) {
  const accept = page.locator('#klaro .cm-btn-success, #klaro button.accept-all, #klaro .cm-btn-accept-all');
  if (await accept.count()) {
    await accept.first().click({ timeout: 3000 }).catch(() => {});
  }
}

async function measureLayout(page) {
  return page.evaluate(() => {
    const body = document.querySelector('.mel-event-studio-section__body');
    const layout = document.querySelector('.mel-event-branding-studio__layout');
    const main = document.querySelector('.mel-event-branding-studio__main');
    const aside = document.querySelector('.mel-event-branding-studio__aside');
    const section = body?.closest('.mel-event-studio-section') ?? body?.parentElement;
    const sectionRect = section?.getBoundingClientRect();
    const bodyRect = body?.getBoundingClientRect();
    const mainRect = main?.getBoundingClientRect();
    const asideRect = aside?.getBoundingClientRect();
    const layoutStyle = layout ? getComputedStyle(layout) : null;

    return {
      hasBody: !!body,
      hasLayout: !!layout,
      hasMain: !!main,
      hasAside: !!aside,
      bodyWidthPct: bodyRect && sectionRect ? Math.round((bodyRect.width / sectionRect.width) * 100) : null,
      mainWidth: mainRect ? Math.round(mainRect.width) : null,
      asideWidth: asideRect ? Math.round(asideRect.width) : null,
      mainAsideRatio: mainRect && asideRect ? Math.round((mainRect.width / asideRect.width) * 100) / 100 : null,
      layoutDisplay: layoutStyle?.display ?? null,
      layoutGridCols: layoutStyle?.gridTemplateColumns ?? null,
      horizontalScroll: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    };
  });
}

async function main() {
  mkdirSync(OUT_DIR, { recursive: true });
  const loginUrl = drushLoginUrl();
  const brandingUrl = `${BASE}/vendor/events/${NID}/studio/branding`;
  const viewports = [
    { name: '1440', width: 1440, height: 900 },
    { name: '1024', width: 1024, height: 768 },
    { name: '768', width: 768, height: 1024 },
    { name: '375', width: 375, height: 812 },
  ];

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
  await dismissChrome(page);
  await page.goto(brandingUrl, { waitUntil: 'networkidle' });
  await dismissChrome(page);
  await page.waitForSelector('.mel-event-branding-studio__layout', { timeout: 15000 });

  const report = { url: brandingUrl, viewports: {} };

  for (const vp of viewports) {
    await page.setViewportSize({ width: vp.width, height: vp.height });
    await page.waitForTimeout(400);
    const shot = join(OUT_DIR, `branding-layout-after-${vp.name}.png`);
    await page.screenshot({ path: shot, fullPage: true });
    report.viewports[vp.name] = {
      screenshot: shot.replace(`${ROOT}/`, ''),
      ...(await measureLayout(page)),
    };
  }

  writeFileSync(join(OUT_DIR, 'branding-layout-split-audit.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  await browser.close();
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
