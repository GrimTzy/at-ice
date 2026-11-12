// Builds the AT-ICE Android app (APK).
//
//   node scripts/build-apk.mjs usb    phone on a USB cable, address http://127.0.0.1:8000
//                                     (run: adb reverse tcp:8000 tcp:8000)
//   node scripts/build-apk.mjs lan    same Wi-Fi as this computer, address http://<this PC>:8000
//                                     (backend: php artisan serve --host=0.0.0.0 --port=8000)
//   node scripts/build-apk.mjs live   the deployed server, address from .env.android-live
//
// The only things that differ between the three are the server address and the
// http/https setting of the app; the app code is the same. See ANDROID-APK.md.

import { spawnSync } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { findJava } from "./android-java.mjs";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const IS_WIN = process.platform === "win32";
const target = (process.argv[2] || "usb").toLowerCase();
const fail = (msg) => { console.error(`\nAPK build stopped: ${msg}\n`); process.exit(1); };

if (!["usb", "lan", "live"].includes(target)) fail(`unknown target "${target}". Use usb, lan or live.`);

// ---- 1. the server address -------------------------------------------------
function readEnvFile(file) {
  const out = {};
  if (!fs.existsSync(file)) return out;
  for (const line of fs.readFileSync(file, "utf8").split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (m) out[m[1]] = m[2].replace(/^["']|["']$/g, "");
  }
  return out;
}

function lanAddress() {
  for (const list of Object.values(os.networkInterfaces())) {
    for (const nic of list || []) {
      if (nic.family === "IPv4" && !nic.internal && /^(192\.168|10\.|172\.(1[6-9]|2\d|3[01]))/.test(nic.address)) return nic.address;
    }
  }
  return null;
}

let apiBase;
if (target === "usb") {
  apiBase = "http://127.0.0.1:8000";
} else if (target === "lan") {
  const ip = lanAddress();
  if (!ip) fail("no Wi-Fi/LAN address found on this computer. Connect to the Wi-Fi first.");
  apiBase = `http://${ip}:8000`;
} else {
  apiBase = readEnvFile(path.join(ROOT, ".env.android-live")).VITE_API_BASE || "";
  if (!/^https:\/\/[^/\s]+/.test(apiBase)) {
    fail("live needs .env.android-live with VITE_API_BASE=https://<your live server> (https only).");
  }
}
apiBase = apiBase.replace(/\/+$/, "");
console.log(`target: ${target}   server: ${apiBase}`);

// ---- 2. the app's own address scheme (http for tests, https for live) -------
const live = target === "live";
// Optional: APK_LIVE_URL=https://<live website> in .env.android-live makes the app open the
// live website itself, so every update of the website reaches the app with no rebuild.
const liveSite = live ? (readEnvFile(path.join(ROOT, ".env.android-live")).APK_LIVE_URL || "").replace(/\/+$/, "") : "";
if (liveSite && !/^https:\/\/[^/\s]+$/.test(liveSite)) fail("APK_LIVE_URL must be an https:// address with no path.");
if (liveSite) console.log(`the app opens the live website: ${liveSite}`);
const capConfig = {
  appId: "com.atice.erp",
  appName: "AT-ICE",
  webDir: "dist",
  ...(live
    ? { server: { androidScheme: "https", ...(liveSite ? { url: liveSite } : {}) } }
    : { server: { androidScheme: "http", cleartext: true }, android: { allowMixedContent: true } }),
};
fs.writeFileSync(path.join(ROOT, "capacitor.config.json"), JSON.stringify(capConfig, null, 2) + "\n");

// ---- 3. web part + copy into the Android project ---------------------------
function run(cmd, args, opts = {}) {
  const r = spawnSync(cmd, args, { stdio: "inherit", shell: IS_WIN, cwd: ROOT, ...opts });
  if (r.status !== 0) fail(`${cmd} ${args.join(" ")} failed.`);
}
// This build's number (seconds since 1970, so always higher than the build before). The app compares
// it with the number the server offers (GET /api/app-version) and asks the user to update when the
// server's is higher.
const BUILD = Math.floor(Date.now() / 1000);
const BUILD_NAME = new Date().toISOString().slice(0, 16).replace("T", " ");
// The app's key (VITE_APP_CLIENT_KEY in .env.android): the server accepts the app's logins and phone
// addresses only with it. It must equal APP_CLIENT_KEY in the backend .env.
const appKey = readEnvFile(path.join(ROOT, ".env.android")).VITE_APP_CLIENT_KEY || "";
if (!appKey) fail("no VITE_APP_CLIENT_KEY in .env.android (copy the APP_CLIENT_KEY of at-ice-backend/.env).");
const env = { ...process.env, VITE_API_BASE: apiBase, VITE_BACKEND_URL: apiBase, VITE_APP_BUILD: String(BUILD), VITE_APP_CLIENT_KEY: appKey };
run("npx", ["vite", "build", "--mode", "android"], { env });
// public/downloads holds the APK itself (for the website button): it must not be packed into the app,
// or each build would carry the one before and grow.
fs.rmSync(path.join(ROOT, "dist", "downloads"), { recursive: true, force: true });
run("npx", ["cap", "sync", "android"], { env });

// ---- 4. Android SDK and a Java the Gradle of this project accepts (17 to 21) -
const sdk = process.env.ANDROID_HOME || process.env.ANDROID_SDK_ROOT || path.join(process.env.LOCALAPPDATA || os.homedir(), "Android", "Sdk");
if (!fs.existsSync(sdk)) fail(`Android SDK not found at ${sdk}. Install Android Studio and open it once.`);
const props = path.join(ROOT, "android", "local.properties");
if (!fs.existsSync(props)) {
  // properties files read "\" as an escape, so write the path with "/"
  fs.writeFileSync(props, `sdk.dir=${sdk.replace(/\\/g, "/").replace(/^([A-Za-z]):/, "$1\\:")}\n`);
}

const javaHome = findJava();
if (!javaHome) fail("no Java 17 to 21 found. Install one (winget install Microsoft.OpenJDK.21). Java 25 is too new for Gradle.");

// ---- 5. the APK ---------------------------------------------------------------
const androidDir = path.join(ROOT, "android");
// full path, quoted: the project folder has spaces and cmd does not search the current folder
const gradle = `"${path.join(androidDir, IS_WIN ? "gradlew.bat" : "gradlew")}"`;
// live = a RELEASE build signed with the one key from npm run apk:keystore (that key is what lets later
// updates install over it). usb / lan = a debug build for testing.
const release = target === "live";
if (release && !fs.existsSync(path.join(androidDir, "keystore.properties"))) {
  fail("the live app must be signed. Run: npm run apk:keystore (once), then back up the two files it prints.");
}
const g = spawnSync(gradle, [release ? "assembleRelease" : "assembleDebug", `-PatVersionCode=${BUILD}`, `-PatVersionName=${BUILD_NAME.replace(" ", "_")}`], {
  stdio: "inherit", shell: true, cwd: androidDir,
  env: { ...process.env, JAVA_HOME: javaHome, PATH: `${path.join(javaHome, "bin")}${path.delimiter}${process.env.PATH}` },
});
if (g.status !== 0) fail("Gradle failed (see above).");

const apk = path.join(androidDir, "app", "build", "outputs", "apk", release ? "release" : "debug", release ? "app-release.apk" : "app-debug.apk");
if (!fs.existsSync(apk)) fail("the APK file was not produced.");

// Proof of who signed it: a release APK must carry the AT-ICE key.
if (release) {
  const tools = path.join(sdk, "build-tools");
  const ver = fs.existsSync(tools) ? fs.readdirSync(tools).sort().pop() : null;
  const signer = ver && path.join(tools, ver, IS_WIN ? "apksigner.bat" : "apksigner");
  if (signer && fs.existsSync(signer)) {
    const v = spawnSync(`"${signer}"`, ["verify", "--print-certs", `"${apk}"`], { shell: true, encoding: "utf8", env: { ...process.env, JAVA_HOME: javaHome } });
    const out = (v.stdout || "") + (v.stderr || "");
    if (v.status !== 0) fail(`the release APK failed the signature check: ${out.trim()}`);
    console.log(out.split(/\r?\n/).filter((l) => /certificate (DN|SHA-256)/i.test(l)).join("\n"));
  }
}

if (release) {
  // A release APK is signed with the live key. It is NOT copied into this computer's backend or
  // website: the phones that test here use the debug key, and a different key cannot update them.
  // The live server gets the file uploaded by hand (see ANDROID-APK.md).
  const liveDir = path.dirname(apk);
  fs.writeFileSync(path.join(liveDir, "app-version.json"), JSON.stringify({ build: BUILD, name: BUILD_NAME }) + "\n");
  console.log(`\nRelease APK ready (live, build ${BUILD}): ${apk}`);
  console.log(`Upload to the live server's backend public/downloads/: ${path.join(liveDir, "app-release.apk")} as AT-ICE.apk, and ${path.join(liveDir, "app-version.json")}.`);
} else {
  // The website's "Download AT-ICE App" button serves this copy.
  const pub = path.join(ROOT, "public", "downloads");
  fs.mkdirSync(pub, { recursive: true });
  fs.copyFileSync(apk, path.join(pub, "AT-ICE.apk"));

  // The backend offers this APK to the installed apps (GET /api/app-version reads these two files).
  const offer = path.join(ROOT, "..", "at-ice-backend", "public", "downloads");
  fs.mkdirSync(offer, { recursive: true });
  fs.copyFileSync(apk, path.join(offer, "AT-ICE.apk"));
  fs.writeFileSync(path.join(offer, "app-version.json"), JSON.stringify({ build: BUILD, name: BUILD_NAME }) + "\n");

  console.log(`\nAPK ready (${target}, build ${BUILD}): ${apk}`);
  console.log("Offered to installed apps: at-ice-backend/public/downloads/ (AT-ICE.apk + app-version.json).");
}
if (target === "usb") console.log("Phone on USB: adb reverse tcp:8000 tcp:8000 && adb install -r the APK above.");
if (target === "lan") console.log("Backend for the network: php artisan serve --host=0.0.0.0 --port=8000 (and allow port 8000 in the firewall).");
if (target === "live") console.log("Backend CORS_ALLOWED_ORIGINS must include https://localhost.");
