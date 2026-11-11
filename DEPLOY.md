# Deploying AT Ice

Production runs as one **Docker Compose** stack built from
`docker-compose.yml` on the `main` branch. A push to `main` redeploys
automatically through the hosting platform's GitHub App; if that build fails,
the running version stays up. GitHub Actions
(`.github/workflows/ci.yml`) builds both images on every push and PR as a check.

```
Internet ─▶ reverse proxy (TLS) ─▶ frontend (nginx :80)
                                     ├─ /          React SPA
                                     ├─ /storage/  delivery-proof photos (shared volume, read-only)
                                     └─ /api, /up ─FastCGI─▶ backend (php-fpm :9000) ─▶ mysql
                                                            worker (queue:work) ───────┘
```

| Service    | Image / build              | Notes |
|------------|----------------------------|-------|
| `frontend` | `at-ice-frontend/Dockerfile` | Only public service. Domains: `https://www.atice.cloud` (canonical) and `https://atice.cloud` (301 to www). |
| `backend`  | `at-ice-backend/Dockerfile`  | Runs `migrate --force` and caches config/routes on every start. |
| `worker`   | same as backend              | Required: broadcast events and every email are queued. |
| `scheduler` | same as backend             | Required: `php artisan schedule:work` runs the time-based jobs every minute (driver reassignment and delay notices, missed-Time-Out memos, payroll cut-off notice, archive expiry). |
| `mysql`    | `mysql:8.4`                  | Must be MySQL; BI queries use `MONTH()`. Data in the `mysql-data` volume. |

## Environment

`.env.production.example` lists the production variables. Set them in the hosting
panel's environment variables for the stack. Only the variables that `docker-compose.yml`
passes through reach the application containers. It does not pass `ATICE_TIMEZONE`,
`PAYROLL_ANCHOR`, `GROQ_VISION_MODEL` or `GROQ_TEXT_MODEL`, and it has no build argument for
`VITE_BUSINESS_TZ`, so setting those does not change the Docker deployment unless
`docker-compose.yml` is changed to pass them (the app uses its built-in defaults). The pay
period anchor can still be changed in Admin > Settings. `PUSHER_APP_KEY` is compiled into
the frontend, so **redeploy** after changing it. The maps use OpenStreetMap and
need no key. `docker-compose.yml` always sets `APP_DEBUG` to false and sets
`CORS_ALLOWED_ORIGINS` from `APP_URL`.

If a key or password was ever committed to Git, treat it as exposed: revoke it in the
provider's console and make a new one. Deleting the file or adding it to `.gitignore`
does not remove it from earlier commits.

Realtime channels are private: the browser signs each subscription at `POST /api/broadcasting/auth` with its login token (roles in `routes/channels.php`).
Realtime stays on `BROADCAST_CONNECTION=log` until `PUSHER_APP_ID` and
`PUSHER_APP_SECRET` are set; then switch it to `pusher`.

## First deploy

After the stack is healthy, create the first admin from a terminal in the
`backend` container:

```sh
php artisan admin:create owner@example.com --name="Owner"
```

It prints a one-time password. Running it again for the same email resets the password.

## Backups

A **scheduled task** (`mysql-backup`, container `mysql`) runs nightly at
02:00 Philippine time (`0 18 * * *` UTC). It writes a consistent, gzipped
`mysqldump` to the `mysql-backups` volume (`/backups` in the mysql container)
and deletes dumps older than 14 days:

```sh
mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers atice | gzip > /backups/atice-$(date +%F-%H%M).sql.gz && find /backups -name 'atice-*.sql.gz' -mtime +14 -delete && ls -lh /backups
```

These dumps live on the same server. To survive losing the server, add an S3
destination in the hosting panel and a storage backup of the `mysql-backups`,
`public-storage` (delivery photos) and `private-storage` (face pictures of
workers and applicants' resumes, never served by nginx) volumes.

**Restore** (mysql container terminal; overwrites current data):

```sh
ls -lh /backups
gunzip < /backups/atice-YYYY-MM-DD-HHMM.sql.gz | mysql -uroot -p"$MYSQL_ROOT_PASSWORD" atice
```

## Release gates and rollback

Before publishing a release, require the CI security audits, SQLite feature tests,
browser regression, MySQL 8.4 migrations/concurrency tests and synthetic restore
drill to pass. The local MySQL 8.0 test run does not replace the MySQL 8.4 CI run.
Pushing `main` triggers deployment, so review these results on a PR first.

The face dependency patch keeps the existing recognition models, 128-value
descriptors and matching threshold. The browser suite executes the real models
with synthetic data; it cannot establish recognition accuracy for real employees.
Before release, test existing enrollments and new enrollment on the actual kiosk,
unknown faces, lighting, camera permission denial, and attendance time-in/out.
The current attendance flow intentionally does not require a blink/head-turn
challenge. Anti-spoofing would require a separately approved behavior change.

### Preserve a recoverable release

Record the currently deployed Git SHA/image digests and intended release SHA.
Take a coordinated backup of MySQL, `public-storage`, `private-storage`, and the
application key/configuration (keep secrets in protected backup storage, not Git).
The app key is needed to decrypt encrypted data. A database dump alone does not
include uploaded photos or private documents.

For a consistent DB-and-files snapshot, pause incoming writes with maintenance
mode, stop the worker and scheduler, and allow in-flight jobs/requests to finish
before taking the dump and both storage archives. Check every command's exit
status, archive readability and SHA-256 hashes. Resume only after those backups
are complete. Copy the backup set off the application server.

### Prove restore before switching traffic

Restore a copy of the dump into a **new staging database**, with new staging
storage volumes. Do not import over the live database as a rehearsal. Restore
both public/private archives, compare table counts and representative records,
and verify attachment hashes and authorized downloads. Disable outgoing mail,
Pusher broadcasts and scheduled jobs in the restored staging environment.

The committed `tests/Support/backup_restore_drill.php` performs a synthetic
MySQL dump/restore, compares all table rows and column definitions, and checks
two synthetic public/private attachment round trips. It refuses existing restore
databases and is restricted to database names containing `test`. It tests the
restore mechanics; it does not prove that the hosting platform's nightly backup
schedule, remote storage destination, or actual production backup is healthy.

### Rollback decision

If a deployment fails before accepting writes and has no incompatible schema
change, redeploy the recorded previous image/SHA and rerun the smoke checks.
Keep maintenance mode until the application, database and attachment versions
are consistent. Do not run `migrate:rollback` blindly.

If the new release accepted writes, restoring the pre-release snapshot would
lose those writes. Preserve a fresh backup and reconcile the intervening records
before any data restore. Test the chosen recovery on staging first and record
the release, backup timestamps, verification results and any accepted data-loss
window. Resume the worker and scheduler and leave maintenance mode only after
the recovered system passes its checks.
