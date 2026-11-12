# AT-ICE Android app (APK)

The app wraps the website with Capacitor. It opens only for the four mobile roles
(Driver, Checker, Salesman, Maintenance). Admin, HR and Customer accounts are
restricted from it. Everything the app shows comes from the same Laravel API and
database as the website, so the app works wherever the website's backend runs
(localhost now, the live server later). The app code is the same in every case; only
the server address and http/https differ, and the build script sets both.

## One-time setup on this computer
1. Install **Android Studio** (Standard setup): it brings the Android SDK.
2. A **Java 17 to 21** (Java 25 is too new for this project's Gradle):
   `winget install Microsoft.OpenJDK.21`. The build script finds it by itself.
3. Phone: Settings > About phone > tap "Build number" 7 times > Developer options > USB debugging.

## Build the APK (one command per situation)
Run in `at-ice-frontend`:

| Command | When | Server address in the app |
|---|---|---|
| `npm run apk` | phone on a USB cable | `http://127.0.0.1:8000` |
| `npm run apk:lan` | phone on the same Wi-Fi as this PC | `http://<this PC's address>:8000` (found automatically) |
| `npm run apk:live` | the deployed server | `VITE_API_BASE` in `.env.android-live` (https only) |

The script also sets the app's http/https mode, builds, and copies the file to
`public/downloads/AT-ICE.apk` (the website's "Download AT-ICE App" button serves it).
The result is `android/app/build/outputs/apk/debug/app-debug.apk`.

### USB
```
adb reverse tcp:8000 tcp:8000
adb install -r android/app/build/outputs/apk/debug/app-debug.apk
```
The backend can stay on `open atice` (127.0.0.1).

### Wi-Fi (no USB)
- Backend for the whole network: `php artisan serve --host=0.0.0.0 --port=8000`
- Allow port 8000 in Windows Firewall (administrator PowerShell):
  `New-NetFirewallRule -DisplayName "AT-ICE dev" -Direction Inbound -Protocol TCP -LocalPort 8000 -Action Allow -Profile Private`
- If this PC's address changes, run `npm run apk:lan` again and reinstall.

### Live
1. Put the live server's address in `.env.android-live` (`VITE_API_BASE=https://...`, not committed).
2. Backend `.env` on the live server: `CORS_ALLOWED_ORIGINS` must include `https://localhost`
   (the default in `config/cors.php` already does, so it only matters if the variable is set).
3. `npm run apk:live`, then give the APK to the users. An APK built for another address
   does not work on the live server: rebuild after any address change.
   **Updates without a new APK:** add `APK_LIVE_URL=https://<live website>` to `.env.android-live`
   before `npm run apk:live`. The app then opens the live website itself, so every change that
   reaches the live site (from `mine/main`) shows in the app at once. It needs internet and the
   live site up. Without it, the app keeps its own copy of the screens: a change to the screens
   (not to the data or backend) needs a new `npm run apk:live` and a reinstall.
4. The website's download button needs the file on the live server. `public/downloads/*.apk`
   is not committed (25 MB), so upload the APK to the live server's `public/downloads/`
   or host it elsewhere and build the website with `VITE_APK_URL=<its https address>`.

## Signing (release APK)
- `npm run apk` and `npm run apk:lan` make a **debug** APK (for testing).
- `npm run apk:live` makes a **release** APK signed with the one AT-ICE key. Make it once with
  `npm run apk:keystore`: it writes `~/.atice/atice-release.jks` and `android/keystore.properties`
  (both never committed). **Back both up and give the same two files to anyone else who builds
  the release.** Android installs an update only over an app signed with the same key; with a
  different key the user must uninstall first. A debug build and a release build are also
  different keys, so uninstall one before installing the other.
- The build prints the signer's certificate to prove which key was used.

## Phone notifications (Firebase)
Every notice that the live feed sends (the same ones the web pop-ups show) also goes to the phone
of the person it is addressed to, even when the app is closed. It plays the AT-ICE sound
(`android/app/src/main/res/raw/at_ice_notification.mp3`).

One-time setup:
1. Firebase console: project `at-ice-e1a9c`, Android app `com.atice.erp`.
2. Download `google-services.json` to `android/app/google-services.json` (git-ignored).
3. Project settings > Service accounts > Generate new private key: save it as
   `at-ice-backend/storage/app/firebase-service-account.json` (git-ignored, never share it).
4. Backend: `composer install` (includes `kreait/firebase-php`). Without the key file, no phone
   notice is sent; everything else still works.

How it works: after sign-in the app asks for permission once and sends its address to
`POST /api/device-tokens`; sign-out removes it (`DELETE /api/device-tokens`) and ends the session
(`POST /api/logout`). Firebase rejects a dead address and it is removed automatically. Only the
four mobile roles have the app, so only they get phone notices.

Tapping a notice opens the app on its home page.

## Release (live) APK
- `npm run apk:keystore` once (see Signing). Back up the two files it prints.
- `npm run apk:live` builds the signed release: `android/app/build/outputs/apk/release/app-release.apk`
  and `app-version.json` beside it. Upload both to the live backend's `public/downloads/`
  (as `AT-ICE.apk` and `app-version.json`). Nothing is copied into this computer's backend or website.
- A phone with the debug build must be uninstalled once before the release build can be installed
  (different key). After that, updates install over it.

## Notes
- The website is not affected: without `VITE_API_BASE` (normal `npm run dev` / `npm run build`)
  every call stays `/api/...` as before.
- Test accounts: `.atice/local-accounts.md` (all `.cloud`).
- Location and camera are used through the phone's own prompts (permissions are in
  `AndroidManifest.xml`). Tracking runs while the app is open; the system does not send the
  driver's position in the background, so no background service is needed.
- App icon source: `assets/icon-only.png`, `icon-foreground.png`, `icon-background.png`.
  After changing them, regenerate with the tool run once outside the project (it is not a project
  dependency: its audit flags moderate issues that CI rejects): `npx @capacitor/assets@4 generate --android --iconBackgroundColor '#7c3aed'`.
