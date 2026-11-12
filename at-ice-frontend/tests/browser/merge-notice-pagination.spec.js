import { test, expect } from '@playwright/test';

const rows = (make) => Array.from({ length: 25 }, (_, index) => make(index + 1));
const product = id => ({ id, name: `Item ${id}`, product_code: `ITEM-${id}`, current_stock: 40, minimum_stock: 10, maximum_stock: 1000, unit: 'pcs' });
const delivery = id => ({ id, delivery_no: `DEL-${id}`, status: 'pending', customer: { name: `Store ${id}`, address: 'Synthetic address' }, items: [], scheduled_date: '2026-11-12', booked: { bags: 5 } });
const scenarios = [
  { name: 'supplier', path: '/checker/suppliers', role: 'checker', record: 'supplier:22', data: { '/api/suppliers': rows(id => ({ id, name: `Supplier ${id}`, supplier_code: `SUP-${id}` })) } },
  { name: 'stock movement hidden by pending filter', path: '/checker/stock-movements', role: 'checker', record: 'stock_movement:22', data: { '/api/stock-movements': rows(id => ({ id, type: 'stock_in', status: 'confirmed', quantity: 1, product: product(id), created_at: '2026-11-12T08:00:00Z' })) } },
  { name: 'maintenance project', path: '/maintenance/projects', role: 'maintenance', record: 'project:22', data: { '/api/my-projects': rows(id => ({ id, name: `Project ${id}`, status: 'planned', requests_total: 0, requests_released: 0 })) } },
  { name: 'parts request hidden by status filter', path: '/maintenance/stock-out-request?status=pending', role: 'maintenance', record: 'stock_out_request:22', data: { '/api/stock-out-requests': rows(id => ({ id, status: 'released', product: product(id), quantity: 1, purpose: 'Synthetic repair', created_at: '2026-11-12T08:00:00Z' })) } },
  { name: 'resolved inventory alert on another tab', path: '/checker/inventory', role: 'checker', record: 'inventory_alert:22', data: { '/api/inventory-alerts': rows(id => ({ id, product: product(id), is_resolved: true, message: `Alert ${id}`, created_at: '2026-11-12T08:00:00Z' })) } },
  { name: 'inventory item opens edit form', path: '/checker/inventory?filter=out', role: 'checker', record: 'product:22', data: { '/api/products': rows(product) } },
  { name: 'ice loading opens the correct count', path: '/checker/ice-loading', role: 'checker', record: 'delivery:22', data: { '/api/ice-loadings/deliveries': rows(id => ({ ...delivery(id), customer: `Store ${id}`, driver: `Driver ${id}`, loaded: false })) } },
  { name: 'driver delivery', path: '/driver/deliveries', role: 'driver', record: 'delivery:22', data: { '/api/driver/deliveries': rows(delivery) } },
  { name: 'salesman stop', path: '/salesman/deliveries', role: 'salesman', record: 'delivery:22', data: { '/api/my-route': { crew: null, drivers: [], route: rows(id => ({ ...delivery(id), stop_no: id })), radius_m: 150 } } },
  { name: 'leave request', path: '/my-leave', role: 'checker', record: 'leave_request:22', data: { '/api/my-leave-requests': rows(id => ({ id, leave_type: 'vacation', status: 'approved', start_date: '2026-11-01', end_date: '2026-11-01', days: 1, paid_days: 1, is_paid: true, reason: `Leave ${id}` })) } },
  { name: 'memo opens its sheet', path: '/checker/suppliers', role: 'checker', record: 'employee_memo:22', memo: true, data: { '/api/my-memos': rows(id => ({ id, memo_no: `MEM-${id}`, kind: 'announcement', level: 'verbal', status: 'sent', subject: `Memo ${id}`, body: 'Synthetic memo', issued_at: '2026-11-12T08:00:00Z', files: [] })) } },
];

for (const scenario of scenarios) test(`notice preserves page-3 navigation: ${scenario.name}`, async ({ page }) => {
  const errors = [], mutations = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.addInitScript(({ role, record, memo }) => {
    localStorage.clear(); sessionStorage.clear();
    localStorage.setItem('token', 'synthetic-integration-token');
    localStorage.setItem('role', role === 'driver' ? 'driver' : 'employee');
    localStorage.setItem('sub_role', role === 'driver' ? '' : role);
    localStorage.setItem('user', JSON.stringify({ id: 999, name: 'Synthetic integration worker' }));
    sessionStorage.setItem('open_record', record);
    if (memo) sessionStorage.setItem('open_memos', '1');
  }, scenario);
  await page.route('**/api/**', async route => {
    const request = route.request(), path = new URL(request.url()).pathname;
    if (!['GET', 'HEAD'].includes(request.method())) mutations.push(`${request.method()} ${path}`);
    const data = scenario.data[path] ?? {
      '/api/my-time-in': { timed_in: true }, '/api/live-feed': { events: [], last_id: 0 },
      '/api/notices': { unread: 0, notices: [] }, '/api/limits': { max_product_quantity: 500 },
      '/api/my-leave-credits': { probationary: false, vacation: { earned: 15, available: 15, used: 0 }, sick: { earned: 15, available: 15, used: 0 }, work_days: [1, 2, 3, 4, 5] },
      '/api/company': {},
    }[path] ?? [];
    await route.fulfill({ json: data });
  });
  await page.goto(scenario.path);
  await page.waitForLoadState('networkidle');
  const target = page.locator(`[data-record="${scenario.record}"]`);
  await expect(target).toBeVisible();
  await expect.poll(() => page.evaluate(() => sessionStorage.getItem('open_record'))).toBeNull();
  if (scenario.record === "product:22") await expect(page.locator("#item-name")).toHaveValue("Item 22");
  if (scenario.name === "ice loading opens the correct count") await expect(page.locator("#ice-count-form")).toContainText("DEL-22");
  if (scenario.name === "salesman stop") await expect(target).toHaveAttribute("aria-expanded", "true");
  if (scenario.name === "driver delivery") await expect(target.locator("..").getByText("Contact", { exact: true })).toBeVisible();
  expect(errors).toEqual([]);
  expect(mutations).toEqual([]);
});

test('same-screen notice clears search and reveals another page without changing records', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('token', 'synthetic'); localStorage.setItem('role', 'employee'); localStorage.setItem('sub_role', 'checker');
  });
  await page.route('**/api/**', route => route.fulfill({ json: new URL(route.request().url()).pathname === '/api/suppliers' ? rows(id => ({ id, name: `Supplier ${id}`, supplier_code: `S-${id}` })) : [] }));
  await page.goto('/checker/suppliers');
  await page.getByRole('searchbox').fill('Supplier 1');
  await page.evaluate(async () => { const { requestNoticeRecord } = await import('/src/lib/noticeRecord.js'); requestNoticeRecord('supplier:22'); });
  await expect(page.locator('[data-record="supplier:22"]')).toBeVisible();
  await expect(page.getByRole('searchbox')).toHaveValue('');
});
