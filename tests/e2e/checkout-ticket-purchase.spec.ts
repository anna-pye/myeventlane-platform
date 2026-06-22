import { test, expect } from '@playwright/test';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { dismissConsentAndChrome } from './helpers/page.js';
import { runDrushPhp } from './helpers/drush.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const runtimeEnvPath = join(__dirname, 'fixtures', 'runtime-env.json');

type RuntimeEnv = {
  bookPath: string;
  eventTitle: string;
  buyerEmail: string;
  paymentMode: string;
};

function loadRuntimeEnv(): RuntimeEnv {
  if (!existsSync(runtimeEnvPath)) {
    throw new Error('Missing tests/e2e/fixtures/runtime-env.json. Run global setup via `npm test`.');
  }
  return JSON.parse(readFileSync(runtimeEnvPath, 'utf8')) as RuntimeEnv;
}

test.describe('Checkout happy path', () => {
  test('customer can buy a ticket and receive confirmation', async ({ page }) => {
    const env = loadRuntimeEnv();
    const buyer = {
      email: env.buyerEmail,
      firstName: 'E2E',
      lastName: 'Buyer',
      holderFirstName: 'Ticket',
      holderLastName: 'Holder',
      holderEmail: env.buyerEmail,
    };

    await page.goto(env.bookPath);
    await dismissConsentAndChrome(page);

    await expect(page.locator('#mel-booking-event-title')).toHaveText(env.eventTitle);

    const quantity = page.getByRole('spinbutton', { name: /Quantity for/i }).first();
    await quantity.fill('1');

    await page.getByRole('button', { name: /Continue to checkout/i }).click();

    await expect(page).toHaveURL(/\/cart/);
    await dismissConsentAndChrome(page);

    const checkoutCta = page.getByRole('button', { name: /checkout/i }).or(page.getByRole('link', { name: /checkout/i }));
    await checkoutCta.first().click();

    await expect(page).toHaveURL(/\/checkout\/\d+\//);
    await dismissConsentAndChrome(page);

    const buyerPane = page.locator('.mel-contact-details-pane');
    await buyerPane.getByLabel(/Email address/i).fill(buyer.email);
    await buyerPane.getByLabel(/First name/i).fill(buyer.firstName);
    await buyerPane.getByLabel(/Last name/i).fill(buyer.lastName);

    const ticketHolders = page.getByRole('region', { name: /Ticket holders/i });
    await ticketHolders.getByRole('textbox', { name: /First name/i }).fill(buyer.holderFirstName);
    await ticketHolders.getByRole('textbox', { name: /Last name/i }).fill(buyer.holderLastName);
    await ticketHolders.getByRole('textbox', { name: /^Email/i }).fill(buyer.holderEmail);

    await page.locator('.mel-consent-checkbox').check();

    const payment = page.getByRole('region', { name: /^Payment/i });
    const paymentFirst = payment.getByRole('textbox', { name: /First name/i });
    if (await paymentFirst.count()) {
      await paymentFirst.fill(buyer.firstName);
      await payment.getByRole('textbox', { name: /Last name/i }).fill(buyer.lastName);
      await payment.getByRole('textbox', { name: /Street address/i }).first().fill('1 E2E Street');
      await payment.getByRole('textbox', { name: /Suburb/i }).fill('Sydney');
      await payment.getByLabel(/State/i).selectOption('New South Wales');
      await payment.getByRole('textbox', { name: /Postal code/i }).fill('2000');
    }

    if (env.paymentMode === 'stripe_test') {
      const stripeFrame = page.frameLocator('iframe[name^="__privateStripeFrame"], iframe[title*="Secure payment"]').first();
      await stripeFrame.locator('[name="number"], input[name="cardnumber"]').fill('4242424242424242');
      await stripeFrame.locator('[name="exp-date"], input[name="exp-date"]').fill('12/34');
      await stripeFrame.locator('[name="cvc"], input[name="cvc"]').fill('123');
      await stripeFrame.locator('[name="postal"], input[name="postal"]').fill('2000');
    }
    else {
      const manualOption = page.getByLabel(/Manual/i);
      if (await manualOption.count()) {
        await manualOption.check();
      }
    }

    await page.getByRole('button', { name: /Complete booking/i }).click();

    await expect(page).toHaveURL(/\/checkout\/\d+\/complete/, { timeout: 60_000 });
    await expect(page.getByRole('heading', { name: /Great choice\. You're all set\./i })).toBeVisible();

    const orderMatch = page.url().match(/\/checkout\/(\d+)\/complete/);
    expect(orderMatch).not.toBeNull();
    const orderId = Number(orderMatch![1]);

    if (env.paymentMode === 'manual') {
      const manualPaymentRaw = runDrushPhp('tests/e2e/scripts/complete-manual-payment.php', {
        MEL_E2E_ORDER_ID: String(orderId),
      });
      const manualPayment = JSON.parse(manualPaymentRaw) as { ok: boolean };
      expect(manualPayment.ok).toBeTruthy();
    }

    const verificationRaw = runDrushPhp('tests/e2e/scripts/verify-order-tickets.php', {
      MEL_E2E_ORDER_ID: String(orderId),
      MEL_E2E_HOLDER_EMAIL: buyer.holderEmail,
    });
    const verification = JSON.parse(verificationRaw) as {
      ok: boolean;
      ticketCount: number;
      orderState: string;
    };

    expect(verification.ok).toBeTruthy();
    expect(verification.orderState).toBe('completed');
    expect(verification.ticketCount).toBeGreaterThanOrEqual(1);
  });
});
