-- READ-ONLY. Run on the target database BEFORE deploying migration
-- 2026_11_06_000001_payrolls_one_per_employee_and_period.
-- Business key: one payroll per employee and pay period (employee_id, period_start).
-- Zero rows = safe to migrate. Any row = the migration will stop; nothing is deleted for you.

-- 1. Employee/period pairs with more than one payroll row
SELECT employee_id, period_start, COUNT(*) AS payroll_rows
FROM payrolls
GROUP BY employee_id, period_start
HAVING COUNT(*) > 1;

-- 2. The rows behind them, to decide which one to keep (keep the paid one, or the latest approval)
SELECT p.id, p.employee_id, p.period_start, p.period_end, p.status, p.paid_at, p.timesheet_approved_at,
       (SELECT COUNT(*) FROM payroll_adjustments a WHERE a.payroll_id = p.id) AS adjustments
FROM payrolls p
JOIN (SELECT employee_id, period_start FROM payrolls GROUP BY employee_id, period_start HAVING COUNT(*) > 1) d
  ON d.employee_id = p.employee_id AND d.period_start = p.period_start
ORDER BY p.employee_id, p.period_start, p.id;
