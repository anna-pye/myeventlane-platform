import type { Page } from '@playwright/test';

/**
 * Dismiss Klaro consent and hide admin toolbar noise for stable selectors.
 */
export async function dismissConsentAndChrome(page: Page): Promise<void> {
  const accept = page.locator('#klaro .cm-btn-success, #klaro button.accept-all, #klaro .cm-btn-accept-all');
  if (await accept.count()) {
    await accept.first().click({ timeout: 5000 }).catch(() => {});
    await page.waitForTimeout(300);
  }

  await page.evaluate(() => {
    document.querySelector('#klaro')?.remove();
    document.querySelector('.cm-bg')?.remove();
    const toolbar = document.querySelector('#toolbar-administration');
    if (toolbar instanceof HTMLElement) {
      toolbar.style.display = 'none';
    }
    document.body.classList.remove('toolbar-loading', 'gin--vertical-toolbar', 'toolbar-tray-open');
  }).catch(() => {});
}
