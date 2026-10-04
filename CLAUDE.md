# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

AT Ice is an ice-business management system (HR/attendance with face recognition, payroll, inventory/stock, deliveries with proof photos and GPS, customers/orders/receipts, BI dashboard). Monorepo with two apps:

- `at-ice-backend/` — Laravel 12 (PHP ^8.2) JSON API, Sanctum token auth, queued broadcast events (Pusher).
- `at-ice-frontend/` — React 19 + Vite 7 + Tailwind 4 + shadcn/Radix SPA (plain JSX, `@` alias → `src/`).

Production is a single Docker Compose stack (`docker-compose.yml`): `frontend` (nginx, the only public service; serves the SPA and forwards `/api` over FastCGI to php-fpm), `backend`, `worker` (`queue:work`, required because events are queued), and `mysql:8.4`. See `DEPLOY.md` for deploy, backups and first-admin creation. Pushes to `main` auto-deploy; CI (`.github/workflows/ci.yml`) builds both images and runs all migrations on fresh MySQL 8.4.

## Commands

Backend (run in `at-ice-backend/`):
- `composer setup` — install, create `.env`, key, migrate, build assets
- `composer dev` — runs `artisan serve`, `queue:listen`, `pail` and vite together
- `composer test` — clears config then `php artisan test`; single test: `php artisan test --filter=TestName` (tests use in-memory SQLite; only the example tests exist currently)
- `./vendor/bin/pint` — code style
- `php artisan admin:create email --name="Name"` — create/reset an admin (prints a one-time password)

Frontend (run in `at-ice-frontend/`):
- `npm run dev` (vite proxies `/api` → `http://localhost:8000`), `npm run build`, `npm run lint`

## Architecture

**Roles and auth.** `users.role` is one of `admin | hr | employee | driver | customer`; `employee` users additionally have `users.sub_role` (`checker | salesman | maintenance`). Backend enforcement is `RoleMiddleware` (alias `role`), applied in groups in `routes/api.php`: e.g. `role:hr`, `role:employee,checker`, `role:employee,checker,maintenance`. Admin bypasses every role check. The frontend mirrors this with `ProtectedRoute allowedRoles={[...]}` in `src/App.jsx` and per-role login pages/layouts (`AdminLayout`, `HrLayout`, `CustomerLayout`); `ProtectedRoute` holds the role/sub_role → login/home path maps. The token, `role`, `sub_role` and `user` are kept in `localStorage` and sent as a Bearer header. When adding a feature for a role, update the route group, the controller, `App.jsx`, and the layout nav.

**Public (unauthenticated) routes** are only login, `face-descriptors` and `POST attendances` — the Attendance Station kiosk (`/attendance-station`, face-api.js in the browser) has no login. These have a `kiosk` throttle; protected routes use `throttle:120,1,api`, login 10/min.

**Backend layout.** Thin controllers per resource in `app/Http/Controllers`, Eloquent models in `app/Models`, one service (`GeocodingService`), four broadcast events in `app/Events` (`AttendanceRecorded`, `DeliveryStatusUpdated`, `LowStockAlert`, `PaymentOverdue`). Realtime uses `BROADCAST_CONNECTION=log` until Pusher credentials are set; the frontend subscribes via `src/echo.js` (Laravel Echo + `VITE_PUSHER_*`).

**Business flows spanning several files.** Stock-out: Maintenance employee files a `StockOutRequest` → Admin approves and releases it (no Checker release step). Deliveries are assigned by Admin. The Driver can only set the status to in_transit and is GPS tracked. The delivery becomes delivered when the Salesman confirms it with quantity and photo proof, which creates the `DeliveryProof` and auto-generates the Receipt. Photos are stored on the public disk (`public-storage` volume, served by nginx at `/storage/`). HR handles employees, face enrollment (`FaceData`), attendance, leave, payroll, applicants/interviews; any employee/driver can file their own leave via `my-leave-requests`.

**Database.** Production must be MySQL (BI queries use `MONTH()`); local dev/tests may use SQLite, which can hide FK/migration-order bugs — CI's MySQL migration run is the source of truth. App timezone is `Asia/Manila` with `DB_TIMEZONE=+08:00`.

**Env / build-time vars.** All production variables are listed in `.env.production.example`. `VITE_*` values (Google Maps key, Pusher key) are baked into the frontend at build time, so changing them requires a redeploy. The backend uses `GROQ_API_KEY` for AI features.

## Project rules

- Delivery confirmation and photo upload belong to the Salesman, NOT the Driver.
- Inventory is supplies/materials only. Ice products are NOT tracked.
- Stock-out release is Admin only, never Checker.
- Do NOT re-implement: geofencing, uniform detection, oil change tracking, fuel efficiency tracking.
- Guards and Baggers have no system account; they are attendance only.
- The `Order` and `Payment` models (with their controllers and routes, e.g. `my-orders`, `my-payments`) are unused legacy code scheduled for later cleanup. Leave them as is for now. The real flow is Delivery > DeliveryProof > Receipt.
