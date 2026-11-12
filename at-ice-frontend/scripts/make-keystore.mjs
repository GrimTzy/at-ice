// Makes the key that signs the release (live) APK:  npm run apk:keystore
//
// ONE key for the whole life of the app. Android only installs an update over an installed app when
// both are signed by the same key, so a lost or replaced key means every user must uninstall first.
//   - the key file goes to <your home>/.atice/atice-release.jks (outside the project, never committed)
//   - android/keystore.properties holds its password (gitignored)
// BACK BOTH UP somewhere safe, and give the same two files to anyone else who builds the live APK.

import { spawnSync } from "node:child_process";
import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { findJava } from "./android-java.mjs";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const props = path.join(ROOT, "android", "keystore.properties");
const dir = path.join(os.homedir(), ".atice");
const jks = path.join(dir, "atice-release.jks");
const fail = (msg) => { console.error(`\nKeystore stopped: ${msg}\n`); process.exit(1); };

if (fs.existsSync(props) || fs.existsSync(jks)) {
  fail(`a key already exists (${fs.existsSync(props) ? props : jks}). It is never replaced here: replacing it breaks updates for every installed app. Delete it yourself only if you are sure.`);
}
const javaHome = findJava();
if (!javaHome) fail("no Java 17 to 21 found (winget install Microsoft.OpenJDK.21).");

const password = crypto.randomBytes(18).toString("base64url");
fs.mkdirSync(dir, { recursive: true });
const keytool = path.join(javaHome, "bin", process.platform === "win32" ? "keytool.exe" : "keytool");
const r = spawnSync(keytool, [
  "-genkeypair", "-keystore", jks, "-alias", "atice", "-keyalg", "RSA", "-keysize", "2048", "-validity", "10950",
  "-storepass", password, "-keypass", password, "-dname", "CN=AT-ICE, OU=ERP, O=Bella Erin Tube Ice, C=PH",
], { stdio: ["ignore", "inherit", "inherit"] });
if (r.status !== 0) fail("keytool failed.");

fs.writeFileSync(props, `storeFile=${jks.replace(/\\/g, "/")}\nstorePassword=${password}\nkeyAlias=atice\nkeyPassword=${password}\n`, { mode: 0o600 });
console.log(`\nKey made:\n  ${jks}\n  ${props}\nBack up BOTH files now (not in git). Without them the live app can never be updated in place.`);
