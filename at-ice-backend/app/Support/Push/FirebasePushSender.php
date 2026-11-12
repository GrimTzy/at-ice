<?php

namespace App\Support\Push;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Throwable;

// Firebase Cloud Messaging (free). The notice shows on the phone's notification bar even when the
// AT-ICE app is closed, with the AT-ICE sound (res/raw/at_ice_notification).
class FirebasePushSender implements PushSender
{
    private static bool $warned = false;

    public function send(array $tokens, string $title, string $body, array $data): array
    {
        $tokens = array_values(array_unique(array_filter($tokens, fn ($t) => is_string($t) && $t !== '')));
        if ($tokens === []) {
            return [];
        }

        $path = (string) config('firebase.credentials');
        if (! is_file($path)) {
            if (! self::$warned) {
                Log::warning("Push: no Firebase key at {$path}; phone notices are not sent.");
                self::$warned = true;
            }

            return [];
        }

        try {
            $messaging = (new Factory)->withServiceAccount($path)->createMessaging();

            $message = CloudMessage::new()
                ->withNotification(Notification::create($title, $body))
                ->withData(array_map('strval', $data))
                ->withAndroidConfig(AndroidConfig::fromArray([
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => (string) config('firebase.channel_id'),
                        'sound' => (string) config('firebase.sound'),
                    ],
                ]));

            return array_values($messaging->sendMulticast($message, $tokens)->invalidTokens());
        } catch (Throwable $e) {
            Log::warning('Push failed: '.$e->getMessage());

            return [];
        }
    }
}
