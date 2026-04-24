const { test, expect } = require('playwright/test');

test.use({ viewport: { width: 390, height: 844 } });

test('mobile login page smoke', async ({ page }) => {
  await page.goto('http://127.0.0.1:8092/login.php', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('form')).toBeVisible();
  await page.screenshot({ path: '/tmp/crm-login-mobile.png', fullPage: true });
});
