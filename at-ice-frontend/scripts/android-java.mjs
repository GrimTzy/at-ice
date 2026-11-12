// Finds a Java the Gradle of this project accepts (17 to 21; Java 25 is too new).
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const IS_WIN = process.platform === "win32";

function javaOk(home) {
  const exe = path.join(home, "bin", IS_WIN ? "java.exe" : "java");
  if (!fs.existsSync(exe)) return false;
  const r = spawnSync(exe, ["-version"], { encoding: "utf8" });
  const major = Number(((r.stderr || "").match(/version "(\d+)/) || [])[1]);
  return major >= 17 && major <= 21;
}

export function findJava() {
  const homes = [process.env.JAVA_HOME];
  for (const base of ["C:\\Program Files\\Microsoft", "C:\\Program Files\\Eclipse Adoptium", "C:\\Program Files\\Java", "C:\\Program Files\\Android\\Android Studio"]) {
    if (fs.existsSync(base)) for (const d of fs.readdirSync(base)) homes.push(path.join(base, d));
  }
  return homes.filter(Boolean).find(javaOk);
}
