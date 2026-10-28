# AT Ice frontend

The web app for AT Ice ERP, built with React and Vite. It talks to the Laravel API in
`../at-ice-backend`. Full setup is in the [main README](../README.md).

## Run it

```bash
npm install
cp .env.example .env   # Windows: copy .env.example .env
npm run dev            # http://localhost:5173
```

The dev server sends `/api` requests to the backend on port 8000, so start the backend first
(`composer dev` in `at-ice-backend`).

## Commands

| Command | What it does |
|---|---|
| `npm run dev` | Starts the dev server |
| `npm run lint` | Checks the code with ESLint |
| `npm run build` | Builds the app into `dist/` |
| `npm run preview` | Serves the built app locally |

## Settings (`.env`)

| Variable | What it is for |
|---|---|
| `VITE_BACKEND_URL` | Address of the API |
| `VITE_PUSHER_APP_KEY`, `VITE_PUSHER_APP_CLUSTER` | Public Pusher settings for instant updates |
| `VITE_BUSINESS_TZ` | Business time zone; must match `ATICE_TIMEZONE` on the server |

Anything starting with `VITE_` is compiled into the page that browsers download, so never put a
password or private key in it. `VITE_LAN_HOST` is filled in by `vite.config.js` in dev; do not set it.

## Notes

- Face recognition models for the Attendance Station are in `public/models`.
- Camera and GPS need HTTPS or `localhost` (see the main README).
- `dist/` is generated and is not committed.
