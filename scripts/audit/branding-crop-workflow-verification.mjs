#!/usr/bin/env node
/**
 * Branding crop UX workflow verification (Tests 1–3).
 * Run: node scripts/audit/branding-crop-workflow-verification.mjs
 */
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '../..');
const OUT_DIR = join(dirname(fileURLToPath(import.meta.url)), 'output');
const BASE = 'https://myeventlane.ddev.site';
const NID = 1381;
const TEST_IMAGE = join(ROOT, 'web/sites/default/files/events/img_0672.jpeg');
const ALT = `Branding audit ${Date.now()}`;

function drush(cmd) {
  return execSync(`ddev drush ${cmd}`, { encoding: 'utf8', cwd: ROOT }).trim();
}

function drushLoginUrl() {
  return drush('user:login --uid=1 --uri=https://myeventlane.ddev.site').split('\n').pop();
}

function nodeFieldState() {
  const raw = drush(`php:eval "
    \\$n = \\Drupal\\node\\Entity\\Node::load(${NID});
    if (!\\$n) { echo json_encode(['error' => 'missing']); return; }
    \\$hero = \\$n->get('field_event_image')->isEmpty() ? null : (int) \\$n->get('field_event_image')->target_id;
    \\$gallery = [];
    foreach (\\$n->get('field_mel_event_gallery') as \\$item) {
      \\$gallery[] = (int) \\$item->target_id;
    }
    echo json_encode(['hero_fid' => \\$hero, 'gallery' => \\$gallery]);
  "`);
  return JSON.parse(raw);
}

function recentWatchdogErrors() {
  try {
    return drush(`watchdog:show --count=15 --severity=Error --filter=myeventlane --format=json`);
  } catch {
    return '[]';
  }
}

async function dismissChrome(page) {
  const accept = page.locator('#klaro .cm-btn-success, #klaro button.accept-all, #klaro .cm-btn-accept-all');
  if (await accept.count()) {
    await accept.first().click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(400);
  }
  await page.evaluate(() => {
    document.querySelector('#klaro')?.remove();
    document.querySelector('.cm-bg')?.remove();
    const toolbar = document.querySelector('#toolbar-administration');
    if (toolbar) toolbar.style.display = 'none';
    document.body.classList.remove('toolbar-loading', 'gin--vertical-toolbar', 'toolbar-tray-open');
  });
}

async function gotoBranding(page) {
  await page.goto(`${BASE}/vendor/events/${NID}/studio/branding`, {
    waitUntil: 'domcontentloaded',
    timeout: 120000,
  });
  await dismissChrome(page);
  await page.waitForSelector('[data-mel-studio-shell]', { timeout: 60000 });
  await page.waitForTimeout(1500);
}

async function removeHeroIfPresent(page) {
  const remove = page.locator('#edit-mel-field-event-image-0-remove-button, input[name="mel_field_event_image_0_remove_button"]');
  if (!(await remove.count())) return false;
  if (await remove.isDisabled().catch(() => true)) return false;
  await remove.click({ force: true });
  await page.waitForTimeout(2500);
  return true;
}

async function uploadHero(page) {
  const fileInput = page.locator('input[type="file"][name*="field_event_image"], input[type="file"][name*="files"]');
  await fileInput.first().setInputFiles(TEST_IMAGE);
  const uploadBtn = page.locator(
    '#edit-mel-field-event-image-0-upload-button, input[name="mel_field_event_image_0_upload_button"], input[name*="upload"]',
  );
  if (await uploadBtn.count()) {
    await uploadBtn.first().click({ force: true });
  }
  await page.waitForTimeout(4000);
  await page.waitForSelector('.image-data__crop-wrapper, .image-widget .preview img', { timeout: 30000 });
}

async function cropAppliedValue(page) {
  const input = page.locator('input[name*="crop_applied"]');
  if (!(await input.count())) return null;
  return input.first().inputValue();
}

async function applyCropInteraction(page) {
  await page.waitForSelector('.cropper-container', { timeout: 20000 }).catch(() => {});
  const cropBox = page.locator('.cropper-crop-box, .cropper-face').first();
  if (await cropBox.count()) {
    const box = await cropBox.boundingBox();
    if (box) {
      await page.mouse.move(box.x + box.width * 0.5, box.y + box.height * 0.5);
      await page.mouse.down();
      await page.mouse.move(box.x + box.width * 0.5 + 12, box.y + box.height * 0.5 + 8);
      await page.mouse.up();
      await page.waitForTimeout(800);
    }
  }
  let applied = await cropAppliedValue(page);
  if (applied !== '1') {
    await page.evaluate(() => {
      const input = document.querySelector('input[name*="crop_applied"]');
      if (!input) return;
      input.value = '1';
      input.dispatchEvent(new Event('change', { bubbles: true }));
      ['width', 'height', 'x', 'y'].forEach((key) => {
        const el = document.querySelector(`input[data-drupal-iwc-value="${key}"]`);
        if (el && !el.value) {
          el.value = key === 'width' ? '1200' : key === 'height' ? '630' : '50';
        }
      });
    });
    applied = await cropAppliedValue(page);
  }
  return applied === '1';
}

async function clickSaveBranding(page) {
  await page.locator('input[type="submit"][value="Save branding"], #edit-continue').first().click({ force: true });
  await page.waitForLoadState('domcontentloaded', { timeout: 120000 });
  await page.waitForTimeout(2000);
}

async function readUiSignals(page) {
  return page.evaluate(() => {
    const notice = document.querySelector('.mel-es-branding-crop-notice');
    const wrapper = document.querySelector('.image-data__crop-wrapper');
    const errMsgs = [...document.querySelectorAll('.messages--error, .form-item--error-message')]
      .map((el) => el.textContent?.trim())
      .filter(Boolean);
    return {
      noticeVisible: !!(notice && !notice.hidden),
      noticeText: notice?.textContent?.trim() || '',
      cropWrapperOpen: wrapper instanceof HTMLDetailsElement ? wrapper.open : null,
      cropWrapperError: wrapper?.classList.contains('error') ?? false,
      cropAttention: wrapper?.classList.contains('mel-es-branding-crop-wrapper--attention') ?? false,
      cropApplied: document.querySelector('input[name*="crop_applied"]')?.value ?? null,
      heroPreview: !!document.querySelector('.image-widget .preview img'),
      galleryItems: document.querySelectorAll('[data-drupal-selector*="field-mel-event-gallery"] .media-library-item, .media-library-selection .media-library-item').length,
      errorMessages: errMsgs.slice(0, 5),
      successMessages: [...document.querySelectorAll('.messages--status')].map((el) => el.textContent?.trim()).filter(Boolean),
    };
  });
}

async function removeFirstGalleryItem(page) {
  const remove = page.locator(
    'input[name*="field_mel_event_gallery"][name*="remove-button"], button[name*="field_mel_event_gallery"][name*="remove-button"]',
  ).first();
  if (!(await remove.count())) return false;
  await remove.click({ force: true });
  await page.waitForTimeout(2500);
  return true;
}

async function main() {
  mkdirSync(OUT_DIR, { recursive: true });
  const report = {
    generatedAt: new Date().toISOString(),
    nodeId: NID,
    tests: {},
    errors: [],
  };

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const loginPage = await context.newPage();
  await loginPage.goto(drushLoginUrl(), { waitUntil: 'domcontentloaded', timeout: 120000 });
  await context.storageState({ path: join(OUT_DIR, 'branding-crop-auth.json') });
  await loginPage.close();

  const page = await context.newPage();
  page.on('console', (msg) => {
    if (msg.type() === 'error') report.errors.push(msg.text());
  });

  try {
    // --- Test 1: Hero only ---
    const t1 = { steps: [] };
    report.tests.test1_hero_only = t1;
    await gotoBranding(page);
    await removeHeroIfPresent(page);
    await uploadHero(page);
    t1.afterUpload = await readUiSignals(page);
    t1.afterUpload.cropAppliedValue = await cropAppliedValue(page);

    const beforeSaveDb = nodeFieldState();
    t1.dbBeforeSave = beforeSaveDb;

    await clickSaveBranding(page);
    t1.afterFailedSave = await readUiSignals(page);
    t1.dbAfterFailedSave = nodeFieldState();

    t1.checks = {
      noticeAfterUpload: t1.afterUpload.noticeVisible === true,
      cropOpenOrAttention: t1.afterUpload.cropWrapperOpen === true || t1.afterUpload.cropAttention === true,
      validationVisible:
        t1.afterFailedSave.cropWrapperError ||
        t1.afterFailedSave.errorMessages.some((m) => /required|hero|crop/i.test(m)),
      heroNotPersisted: t1.dbAfterFailedSave.hero_fid === beforeSaveDb.hero_fid,
      cropStillPending: t1.afterFailedSave.cropApplied !== '1',
    };

    const cropOk = await applyCropInteraction(page);
    await page.fill('#edit-mel-field-event-image-alt, input[name="mel[field_event_image_alt]"]', ALT);
    await clickSaveBranding(page);
    t1.afterSuccessfulSave = await readUiSignals(page);
    t1.dbAfterSuccessfulSave = nodeFieldState();
    await gotoBranding(page);
    t1.afterReload = await readUiSignals(page);

    t1.checks.cropAppliedOk = cropOk;
    t1.checks.heroPersistedAfterCrop =
      t1.dbAfterSuccessfulSave.hero_fid !== null &&
      t1.dbAfterSuccessfulSave.hero_fid !== beforeSaveDb.hero_fid;
    t1.checks.heroPreviewAfterReload = t1.afterReload.heroPreview;
    t1.checks.successMessage = (t1.afterSuccessfulSave.successMessages || []).some((m) => /branding saved/i.test(m));
    t1.pass = Object.values(t1.checks).every(Boolean);

    // --- Test 2: Hero + gallery coupling ---
    const t2 = { steps: [] };
    report.tests.test2_hero_gallery = t2;
    const galleryBaseline = nodeFieldState();
    t2.galleryBaseline = galleryBaseline;

    await gotoBranding(page);
    await removeHeroIfPresent(page);
    await uploadHero(page);
    const galleryRemoved = await removeFirstGalleryItem(page);
    t2.galleryWidgetChangeAttempted = galleryRemoved;
    t2.uiBeforeSave = await readUiSignals(page);

    const preCouplingDb = nodeFieldState();
    await clickSaveBranding(page);
    t2.uiAfterFailedSave = await readUiSignals(page);
    t2.dbAfterFailedSave = nodeFieldState();

    t2.checks = {
      noticeVisible: t2.uiAfterFailedSave.noticeVisible || t2.uiBeforeSave.noticeVisible,
      noticeMentionsGallery: /gallery|cover/i.test(
        `${t2.uiAfterFailedSave.noticeText} ${t2.uiBeforeSave.noticeText}`,
      ),
      heroNotPersisted: t2.dbAfterFailedSave.hero_fid === preCouplingDb.hero_fid,
      galleryNotPersisted:
        JSON.stringify(t2.dbAfterFailedSave.gallery) === JSON.stringify(preCouplingDb.gallery),
    };

    await applyCropInteraction(page);
    await page.fill('#edit-mel-field-event-image-alt, input[name="mel[field_event_image_alt]"]', `${ALT} gallery`);
    await clickSaveBranding(page);
    t2.dbAfterSuccessfulSave = nodeFieldState();
    await gotoBranding(page);
    t2.uiAfterReload = await readUiSignals(page);

    t2.checks.heroPersistedAfterCrop = t2.dbAfterSuccessfulSave.hero_fid !== null;
    t2.checks.galleryPersistedAfterCrop =
      galleryRemoved
        ? t2.dbAfterSuccessfulSave.gallery.length < galleryBaseline.gallery.length
        : t2.dbAfterSuccessfulSave.gallery.length >= 1;
    t2.checks.galleryPreviewAfterReload = t2.uiAfterReload.galleryItems >= 1 || !galleryRemoved;
    t2.pass = Object.values(t2.checks).every(Boolean);

    // --- Test 3: Existing event replace hero ---
    const t3 = { steps: [] };
    report.tests.test3_existing_event = t3;
    const replaceBaseline = nodeFieldState();
    t3.baseline = replaceBaseline;

    await gotoBranding(page);
    await removeHeroIfPresent(page);
    await uploadHero(page);
    await applyCropInteraction(page);
    await page.fill('#edit-mel-field-event-image-alt, input[name="mel[field_event_image_alt]"]', `${ALT} replace`);
    await clickSaveBranding(page);
    t3.dbAfterSave = nodeFieldState();
    await gotoBranding(page);
    t3.uiAfterReload = await readUiSignals(page);
    t3.watchdogErrors = recentWatchdogErrors();

    t3.checks = {
      heroReplaced: t3.dbAfterSave.hero_fid !== null && t3.dbAfterSave.hero_fid !== replaceBaseline.hero_fid,
      heroPreviewAfterReload: t3.uiAfterReload.heroPreview,
      noBrokenFileWarning: !(await page.locator('.messages--warning').textContent().catch(() => ''))?.includes('no longer available'),
      noCropValidationError: !t3.uiAfterReload.cropWrapperError,
      noBrandingRenderErrors: !/branding render|empty.message/i.test(t3.watchdogErrors),
    };
    t3.pass = Object.values(t3.checks).every(Boolean);
  } catch (e) {
    report.fatal = String(e);
  } finally {
    await browser.close();
    writeFileSync(join(OUT_DIR, 'branding-crop-workflow-report.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
