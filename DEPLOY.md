# Deploying AT Ice (Coolify)

Production runs as one Coolify **Docker Compose** resource built from
`docker-compose.yml` on the `main` branch. A push to `main` redeploys
automatically.

```
Internet ─▶ Coolify proxy (TLS) ─▶ frontend (nginx :80)
                                     ├─ /          React SPA
                                     ├─ /storage/  delivery-proof photos (shared volume, read-only)
                                     └─ /api, /up ─FastCGI─▶ backend (php-fpm :9000) ─▶ mysql
                                                            worker (queue:work) ───────┘
```

| Service    | Image / build              | Notes |
|------------|----------------------------|-------|
| `frontend` | `at-ice-frontend/Dockerfile` | Only public service. Domains: `https://www.atice.cloud` (canonical) and `https://atice.cloud` (301 to www). |
| `backend`  | `at-ice-backend/Dockerfile`  | Runs `migrate --force` and caches config/routes on every start. |
| `worker`   | same as backend              | Required: broadcast events (attendance, deliveries, low stock, overdue) are queued. |
| `mysql`    | `mysql:8.4`                  | Must be MySQL; BI queries use `MONTH()`. Data in the `mysql-data` volume. |

## Environment

All variables are listed in `.env.production.example`. Set them in Coolify under
the resource's Environment Variables. `VITE_GOOGLE_MAPS_API_KEY` and
`PUSHER_APP_KEY` are compiled into the frontend, so **redeploy** after changing them.

Realtime stays on `BROADCAST_CONNECTION=log` until `PUSHER_APP_ID` and
`PUSHER_APP_SECRET` are set; then switch it to `pusher`.

## First deploy

After the stack is healthy, create the first admin from the Coolify terminal
(`backend` container):

```sh
php artisan admin:create owner@example.com --name="Owner"
```

It prints a one-time password. Running it again for the same email resets the password.

## Backups

The `mysql-data` volume holds all business data. Schedule a dump, e.g.
`mysqldump` via a Coolify scheduled task on the `mysql` service, and copy it
off the server.
