-- READ-ONLY. Run on the target database BEFORE deploying migration
-- 2026_11_06_000002_attendances_one_open_per_person_and_day.
-- Business key: a person (face_data_id) has at most ONE open record (time_out IS NULL) per date.
-- Open records on other dates are allowed (a forgotten Time Out), closed records never count.
-- Zero rows = safe to migrate. Any row = the migration will stop; nothing is deleted for you.

-- 1. Person/day pairs with more than one open attendance row
SELECT face_data_id, date, COUNT(*) AS open_rows
FROM attendances
WHERE time_out IS NULL AND face_data_id IS NOT NULL
GROUP BY face_data_id, date
HAVING COUNT(*) > 1;

-- 2. The rows behind them, with any memo already written for them (keep the earliest row)
SELECT a.id, a.face_data_id, a.employee_id, a.date, a.time_in, a.created_at,
       (SELECT COUNT(*) FROM employee_memos m WHERE m.attendance_id = a.id) AS memos
FROM attendances a
JOIN (SELECT face_data_id, date FROM attendances WHERE time_out IS NULL AND face_data_id IS NOT NULL
      GROUP BY face_data_id, date HAVING COUNT(*) > 1) d
  ON d.face_data_id = a.face_data_id AND d.date = a.date
WHERE a.time_out IS NULL
ORDER BY a.face_data_id, a.date, a.time_in, a.id;
