import { test, expect } from '@playwright/test';

for (const timezoneId of ['Asia/Manila', 'UTC', 'America/Los_Angeles']) {
  test.describe(timezoneId, () => {
    test.use({ timezoneId, reducedMotion: 'reduce' });

    test('deadline clock and date validation use business time', async ({ page }) => {
      await page.clock.install({ time: new Date('2026-11-10T17:00:00Z') });
      await page.goto('/login');
      const actual = await page.evaluate(async () => {
        const deadline = await import('/src/lib/deliverBy.js');
        const dates = await import('/src/pages/Admin/deliveries/utils.js');
        return { display: deadline.deliverByTime('2026-11-11T00:00:00Z'), value: deadline.deliverByValue('2026-11-11T00:00:00Z'), midnight: deadline.deliverByValue('2026-11-10T16:00:00Z'), now: dates.nowHm(), today: dates.todayStr() };
      });
      expect(actual).toEqual({ display: '8:00 AM', value: '08:00', midnight: '00:00', now: '01:00', today: '2026-11-11' });
      await page.clock.setSystemTime(new Date('2026-11-15T16:30:00Z'));
      const monday = await page.evaluate(async () => {
        const dates = await import('/src/pages/Admin/deliveries/utils.js');
        return { today: dates.todayStr(), next: dates.nextMonday() };
      });
      expect(monday).toEqual({ today: '2026-11-16', next: '2026-11-16' });
    });

    test('editing and saving preserves the stored deadline', async ({ page }) => {
      await page.goto('/login');
      await page.locator('#login-email').fill('admin@browser-test.invalid');
      await page.locator('#login-password').fill('Browser-Test!2026');
      await page.getByRole('button', { name: 'Sign In', exact: true }).click();
      await expect(page).toHaveURL(/\/dashboard$/);
      const token = await page.evaluate(() => localStorage.getItem('token'));
      const headers = { Authorization: `Bearer ${token}` };
      const response = await page.request.get('/api/deliveries', { headers });
      expect(response.ok()).toBe(true);
      const delivery = (await response.json()).find(row => row.delivery_no === 'DEL-0001');
      expect(delivery).toBeTruthy();
      await page.goto('/admin/deliveries?tab=stores');
      await page.waitForLoadState('networkidle');
      const date = delivery.scheduled_date.slice(0, 10);
      await Promise.all([
        page.waitForResponse(result => result.url().endsWith(`/api/deliveries/board?date=${date}`) && result.ok()),
        page.locator('input[name=board_date]').fill(date),
      ]);
      await page.waitForLoadState('networkidle');
      const row = page.getByRole('row').filter({ hasText: 'Deadline branch' });
      await expect(row).toContainText('8:00 AM');
      await row.getByRole('button', { name: 'Edit', exact: true }).click();
      await expect(page.locator('#edit-delivery-deliver-by')).toHaveValue('08:00');
      await page.getByRole('button', { name: 'Save', exact: true }).click();
      await page.locator('.guard-card button[type=submit]').click();
      await expect(page.locator('#edit-delivery-deliver-by')).toHaveCount(0);
      const after = await page.request.get('/api/deliveries', { headers });
      expect(after.ok()).toBe(true);
      const saved = (await after.json()).find(item => item.id === delivery.id);
      expect(saved.due_at).toBe(delivery.due_at);
      await expect(row).toContainText('8:00 AM');
    });
  });
}
