import { registerPlugin } from "@capacitor/core";

// Native part (android/app/src/main/java/com/atice/erp/AppUpdatePlugin.java):
// download(url) -> { path }  with "progress" events { percent }, install() opens Android's installer.
export const AppUpdate = registerPlugin("AppUpdate");
