# HR and Admin access

Client rule: HR edits operational data (schedules, employee work and payroll
details). Admin monitors the whole system and gives management approvals
(memos, final decisions). Admin keeps its staff functions (deliveries, prices,
customers, inventory).

How it is enforced:

- `routes/api.php` groups routes under `role:admin` and `role:hr`.
- `RoleMiddleware` lets Admin pass every role check, so Admin can open any
  `role:hr` route unless something stops it.
- `EnsureHrOnly` blocks Admin on the payroll routes and on Rates and rules.
- `EmployeeController::update` blocks Admin from changing a work schedule.
- The menus are in `src/lib/nav.jsx` (`ADMIN_NAV`, `HR_NAV`), and the page
  rules are in `ProtectedRoute` in `App.jsx`.

V = view, E = edit, A = approve, - = no access.

| Feature | HR | Admin | Notes |
|---|---|---|---|
| Employees: add, edit details, deactivate | V E | V | Admin sees the read-only list (`employees-summary`). Admin's API access to the edit routes comes from the role override. |
| Work schedule (shift hours) and its history | V E | V | Admin gets 403 when changing a shift. |
| Employee documents | V E | - | HR menu only |
| Attendance records and corrections | V E | V | Admin sees today's attendance on the Dashboard. |
| Leave requests | V A | - | HR approves or rejects. |
| Leave balances | V | - | HR > Leave Requests |
| Timesheets and overtime | V A | - | `EnsureHrOnly` |
| Payroll records, adjustments, payslips, 13th month | V E | - | `EnsureHrOnly` |
| Disciplinary memos: draft, verbal and first written | V E | - | HR releases these directly. |
| Formal memos (final written, NTE, zero tolerance) | V E | V A | HR drafts and endorses, Admin approves or returns, HR releases. |
| Announcements to employees | V E | - | Sent by HR directly. |
| Holidays | V E | V E | Both, as before. |
| Kiosk PIN | V E | - | HR. The Admin PIN only unlocks a locked kiosk. |
| Kiosk override PIN | V E (HR override PIN) | V E (Admin override PIN) | Either one unlocks a locked kiosk; HR's must differ from the kiosk PIN (owner 2026-11-10). |
| Performance appraisals and history | - | V E A | Admin publishes ratings. |
| Rates and rules, holiday and overtime pay rates | V E | - | HR > Rates and rules menu (one page per item), `EnsureHrOnly` (moved from Admin, owner 2026-11-09) |
| HR kiosk unlock (`kiosk.hr_can_override`), record expiry (`archive.expiry_action`) | - | V E | Kept with Admin (`Settings::ADMIN_KEYS`): Settings > Admin PIN and Old records |
| Delivery and support ticket settings (FIFO, rolling stock, alerts, overdue tickets) | - | V E | Admin > Settings > Deliveries and Support tickets (`Settings::ADMIN_PAGES`, moved from HR 2026-11-10) |
| Recent changes (audit log) | - | V | Settings > Recent changes |
| Deliveries, contracts, driver assignments | - | V E A | Admin staff function |
| Customers, prices, invoices, payment proofs | - | V E A | Admin staff function |
| Support tickets and escalation | - | V E | Admin staff function |
| Inventory: stock-out approvals, limits, projects | - | V E A | Admin staff function |
| Business intelligence | - | V | Dashboard |
| Old records (retention) | - | V E | Admin decides to keep, delete or anonymize. |

## Left as is (changing would break current behaviour)

- Admin passes every `role:hr` route because of the role override. Admin
  could still edit employee details other than the schedule, attendance
  corrections, leave decisions and holidays through the API. No Admin page
  does this. Blocking it needs the owner's decision, because the role
  override is used across the system and in many tests.
- The default shift hours and the night hours are in Rates and rules, which
  HR edits. They are company rules, not one employee's schedule.
