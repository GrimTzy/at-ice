package com.atice.erp;

import android.content.Intent;
import android.net.Uri;
import android.provider.Settings;

import androidx.core.content.FileProvider;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;

// Updates the app from inside the app: download the new APK with progress, then hand it to Android's
// own installer. Android always asks the user to confirm the install (only a store or a device owner
// can install silently), so the user taps Install once.
@CapacitorPlugin(name = "AppUpdate")
public class AppUpdatePlugin extends Plugin {

    private static final String FILE_NAME = "AT-ICE-update.apk";

    // Asks for the "Install unknown apps" permission first, so nothing is downloaded before the
    // install can happen. Returns false (and opens the settings screen) when it is not allowed yet.
    private boolean allowInstallFirst(PluginCall call) {
        if (getContext().getPackageManager().canRequestPackageInstalls()) return true;
        Intent settings = new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
            Uri.parse("package:" + getContext().getPackageName()));
        settings.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        getContext().startActivity(settings);
        call.reject("Allow AT-ICE to install apps, then tap Update again.");
        return false;
    }

    @PluginMethod
    public void download(PluginCall call) {
        String address = call.getString("url");
        if (address == null || !address.startsWith("http")) {
            call.reject("No download address.");
            return;
        }
        if (!allowInstallFirst(call)) return;

        new Thread(() -> {
            HttpURLConnection conn = null;
            try {
                File dir = new File(getContext().getCacheDir(), "updates");
                if (!dir.exists() && !dir.mkdirs()) {
                    call.reject("Cannot create the update folder.");
                    return;
                }
                File target = new File(dir, FILE_NAME);
                if (target.exists()) target.delete();

                conn = (HttpURLConnection) new URL(address).openConnection();
                conn.setConnectTimeout(15000);
                conn.setReadTimeout(30000);
                conn.connect();
                if (conn.getResponseCode() != 200) {
                    call.reject("The update is not available (HTTP " + conn.getResponseCode() + ").");
                    return;
                }
                String type = conn.getContentType();
                if (type != null && type.toLowerCase().startsWith("text/html")) {
                    call.reject("The update is not available yet.");
                    return;
                }

                long total = conn.getContentLengthLong();
                long done = 0;
                int lastPercent = -1;
                try (InputStream in = conn.getInputStream(); FileOutputStream out = new FileOutputStream(target)) {
                    byte[] buf = new byte[64 * 1024];
                    int n;
                    while ((n = in.read(buf)) != -1) {
                        out.write(buf, 0, n);
                        done += n;
                        if (total > 0) {
                            int percent = (int) (done * 100 / total);
                            if (percent != lastPercent) {
                                lastPercent = percent;
                                JSObject p = new JSObject();
                                p.put("percent", percent);
                                notifyListeners("progress", p);
                            }
                        }
                    }
                }

                if (total > 0 && done != total) {
                    target.delete();
                    call.reject("The download was cut short. Try again.");
                    return;
                }
                JSObject result = new JSObject();
                result.put("path", target.getAbsolutePath());
                result.put("bytes", done);
                call.resolve(result);
            } catch (Exception e) {
                call.reject("Download failed: " + e.getMessage());
            } finally {
                if (conn != null) conn.disconnect();
            }
        }).start();
    }

    @PluginMethod
    public void install(PluginCall call) {
        File file = new File(new File(getContext().getCacheDir(), "updates"), FILE_NAME);
        if (!file.exists()) {
            call.reject("Download the update first.");
            return;
        }

        if (!allowInstallFirst(call)) return;

        try {
            Uri uri = FileProvider.getUriForFile(getContext(), getContext().getPackageName() + ".fileprovider", file);
            Intent install = new Intent(Intent.ACTION_VIEW);
            install.setDataAndType(uri, "application/vnd.android.package-archive");
            install.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
            getContext().startActivity(install);
            call.resolve();
        } catch (Exception e) {
            call.reject("Cannot open the installer: " + e.getMessage());
        }
    }
}
