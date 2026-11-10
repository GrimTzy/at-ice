import { test, expect } from '@playwright/test';

async function login(page, role) {
  await page.goto('/login');
  await page.locator('#login-email').fill(`${role}@browser-test.invalid`);
  await page.locator('#login-password').fill('Browser-Test!2026');
  await page.getByRole('button', { name: 'Sign In', exact: true }).click();
  await expect(page).toHaveURL(role === 'admin' ? /\/dashboard$/ : /\/driver\/deliveries$/);
}

test('optional Pusher stays optional across admin and driver pages', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await login(page, 'admin');
  await expect(page.getByText('Dashboard', { exact: true }).last()).toBeVisible();
  await page.goto('/admin/receipts');
  await expect(page.getByText('No invoices yet.', { exact: true })).toBeVisible();
  await page.goto('/a-route-that-does-not-exist');
  await expect(page).toHaveURL(/\/dashboard$/);
  await expect(page.getByText('Dashboard', { exact: true }).last()).toBeVisible();
  await page.evaluate(() => localStorage.clear());
  await login(page, 'driver');
  await expect(page.getByText('My Deliveries', { exact: true })).toBeVisible();
  await page.goto('/admin/customers');
  await expect(page).toHaveURL(/\/driver\/deliveries$/);
  await expect(page.getByText('My Deliveries', { exact: true })).toBeVisible();
  expect(errors).toEqual([]);
});

test('kiosk handles missing camera and blocked phones without a blank page', async ({ page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(navigator.mediaDevices, 'enumerateDevices', { value: async () => [] });
  });
  await page.goto('/attendance-station');
  await expect(page.getByText('No Camera Detected', { exact: true })).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/attendance-station');
  await expect(page.getByText('Access Restricted', { exact: true })).toBeVisible();
});

test('admin creates and assigns a route and one-off delivery requires a deadline', async ({ page }) => {
  await login(page, 'admin');
  await page.goto('/admin/deliveries?tab=routes');
  await page.getByRole('button', { name: 'New Route', exact: true }).click();
  const name = `Smoke route ${Date.now()}`;
  await page.locator('#route-name').fill(name);
  await page.getByRole('button', { name: 'Save route', exact: true }).click();
  await page.locator('.guard-card button[type=submit]').click();
  await expect(page.locator('#route-name')).toHaveCount(0);
  await expect(page.getByText(name, { exact: true }).first()).toBeVisible();
  await page.goto('/admin/deliveries?tab=assign');
  const driver = page.locator('#assign-driver option').filter({ hasText: 'Smoke Driver' });
  const route = page.locator('#assign-route option').filter({ hasText: name });
  await expect(driver).toHaveCount(1);
  await expect(route).toHaveCount(1);
  await page.locator('#assign-driver').selectOption(await driver.getAttribute('value'));
  await page.locator('#assign-route').selectOption(await route.getAttribute('value'));
  await page.locator('#assign-length').selectOption('week');
  const from = await page.locator('#assign-from').inputValue();
  const until = await page.locator('#assign-until').inputValue();
  expect((Date.parse(until) - Date.parse(from)) / 86400000).toBe(6);
  await page.locator('#assign-length').selectOption('open');
  await expect(page.locator('#assign-until')).toHaveValue('');
  await page.locator('#assign-length').selectOption('day');
  await expect(page.locator('#assign-until')).toHaveValue(await page.locator('#assign-from').inputValue());
  const [assignment] = await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/driver-route-assignments') && response.request().method() === 'POST'),
    page.getByRole('button', { name: 'Assign route', exact: true }).click(),
  ]);
  expect(assignment.ok()).toBe(true);
  await expect(page.getByText(name, { exact: true }).last()).toBeVisible();
  await page.goto('/admin/deliveries?tab=list');
  await page.getByRole('button', { name: 'One-off Delivery', exact: true }).click();
  await expect(page.locator('input[name=deliver_by]')).toHaveAttribute('required', '');
  await page.getByRole('button', { name: 'Create Delivery', exact: true }).click();
  await expect(page.locator('form[data-tried] input[name=deliver_by]:invalid')).toHaveCount(1);
  await expect(page.locator('.guard-card')).toHaveCount(0);
});

test('face models load and saved 128-value descriptors remain matchable', async ({ page }) => {
  await page.goto('/login');
  const result = await page.evaluate(async () => {
    const { probeFaceModels } = await import('/tests/browser/face-probe.js');
    return probeFaceModels();
  });
  expect(result).toEqual({ known: 'existing-worker', unknown: 'unknown', emptyFrame: true });
});
