# Payroll and attendance integrity: setup notes

Two migrations add database rules that stop duplicate payrolls and duplicate open attendance records:

| Migration | Rule |
|---|---|
| `2026_11_06_000001_payrolls_one_per_employee_and_period` | one payroll per employee and pay period (`employee_id`, `period_start`) |
| `2026_11_06_000002_attendances_one_open_per_person_and_day` | one open attendance record (no Time Out) per person per day; closed records and open records of other days are not limited |

## Before you migrate: check for existing duplicates

The migrations never delete or merge anything. If your local database already has duplicates (for example from testing the kiosk or the timesheet approval with double clicks), the migration **stops with a message that includes the query**, changes nothing, and you fix the rows by hand.

Read-only detection queries (zero rows from the first query of each file means you are clear):

```bash
# SQLite (default local database)
sqlite3 database/database.sqlite < database/sql/payroll-duplicates.sql
sqlite3 database/database.sqlite < database/sql/attendance-duplicates.sql

# MySQL
mysql -u USER -p DATABASE_NAME < database/sql/payroll-duplicates.sql
mysql -u USER -p DATABASE_NAME < database/sql/attendance-duplicates.sql
```

No `sqlite3` or `mysql` command? Run `php artisan migrate` instead: it runs the same check first and prints the duplicates it found.

**`migrate` is not all-or-nothing.** It applies pending migrations one by one, so older pending migrations and the payroll migration can already be applied when the attendance migration stops on duplicates (tested: the older `create_demo_dataset_records_table` and the payroll migration were applied, the attendance one stayed Pending). That is fine: fix the duplicates, run `php artisan migrate` again, and only the still-pending migration runs. Check with `php artisan migrate:status` at any time.

If there are duplicates, for each pair:
- payroll: keep the paid row (or the latest approval if none is paid), move or re-enter its adjustments, delete the others;
- attendance: keep the earliest row (the real Time In), check whether the others have memos (`employee_memos.attendance_id`), delete the extras.
Then run the migration again. Make a copy of the database first.

## Commands for a local database

```bash
cd at-ice-backend

# 1. copy of the database (SQLite)
cp database/database.sqlite database/database.backup.sqlite      # Windows cmd: copy database\database.sqlite database\database.backup.sqlite

# 2. apply the two new migrations (stops safely if duplicates exist)
php artisan migrate

# 3. check
php artisan test --exclude-filter=ThesisDemoDatasetTest
php artisan test tests/Feature/ThesisDemoDatasetTest.php
```

Why two test commands: a single `php artisan test` can stop at "Maximum execution time of 75 seconds exceeded", because `DocumentScanController` leaves a 75-second time limit in the test process (an existing problem, not changed). Split like above, or run only the files you need.

Requirements: SQLite 3.31 or newer (generated columns; PHP's bundled SQLite is normally newer, check with `php -r "echo (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn();"`) or MySQL 5.7 or newer (tested on 8.0.46; MySQL 8.4 is not verified yet).

## Undo (local only): base it on `migrate:status`, not on a fixed number

```bash
php artisan migrate:status          # the LAST migration marked Ran is the one a rollback undoes
php artisan migrate:rollback --step=1
php artisan migrate:status          # check again, then repeat only if you want the next one undone
```

The two migrations to undo, newest first, are `2026_11_06_000002_attendances_one_open_per_person_and_day` (only if it is `Ran`) and then `2026_11_06_000001_payrolls_one_per_employee_and_period`. Stop when the last `Ran` migration is an older one: a rollback goes by order of application, not by name. **Do not use a blanket `--step=2`:** if the attendance migration stopped on duplicates (so it is still Pending), `--step=2` would undo the payroll migration **and the older migration before it** (tested: it would drop `create_demo_dataset_records_table`). Use `--pretend` first to see what a rollback would do.

A plain `migrate:rollback`, `migrate:reset` or `migrate:fresh` on MySQL stops at the older migration `unique_ice_loading_per_delivery`, whose `down()` fails there (existing issue, not changed). On SQLite use `migrate:fresh --seed` freely if you only want a clean local database.

## Known limitation (business rule not decided): a missed Time Out

If someone forgets to Time Out, the record stays open and **HR cannot correct it**: times are never edited by hand, only a note can be added. This can affect payable hours: the open record counts as a day present, but its `hours_worked` is 0, and holiday pay and overtime are calculated from the hours, so they come out as 0 for that day. On the same date the person cannot Time In again; from the next date they scan normally. The rule for HR to close a missed record (or to allow a second record) is still to be decided with the owner.

## Real parallel tests on MySQL (optional)

`tests/Feature/ConcurrentWritesMysqlTest.php` sends real simultaneous requests and is skipped unless you point the tests at a throwaway MySQL database whose name contains `test` or `audit` (it runs `migrate:fresh` on it):

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=atice_test DB_USERNAME=root DB_PASSWORD= \
  php artisan test --filter=ConcurrentWritesMysqlTest
```
