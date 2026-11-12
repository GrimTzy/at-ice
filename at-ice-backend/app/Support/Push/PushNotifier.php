<?php

namespace App\Support\Push;

use App\Models\DeviceToken;
use App\Models\LiveEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

// Sends the phone notice for a live-feed notice (LiveEvent) to the people it is addressed to, the
// same people the web pop-up reaches: the roles and user ids of the event, minus the person who
// did the action. Only the four mobile roles have phones with the app, so only they are reached.
class PushNotifier
{
    public static function forLiveEvent(LiveEvent $event): void
    {
        try {
            $tokens = self::tokensFor($event);
            if ($tokens === []) {
                return;
            }

            $invalid = app(PushSender::class)->send(
                $tokens,
                'AT-ICE',
                $event->message,
                array_filter([
                    'module' => (string) $event->module,
                    'action' => (string) $event->action,
                    'event_id' => (string) $event->id,
                    // the record the notice is about: the app opens it (e.g. delivery:12)
                    'record' => $event->subject_type && $event->subject_id ? $event->subject_type.':'.$event->subject_id : null,
                ]),
            );

            if ($invalid !== []) {
                DeviceToken::whereIn('token', $invalid)->delete();
            }
        } catch (Throwable $e) {
            Log::warning('Push: '.$e->getMessage());
        }
    }

    /** @return list<string> the phone addresses of everyone this notice is addressed to */
    public static function tokensFor(LiveEvent $event): array
    {
        $ids = array_values(array_filter((array) $event->user_ids));
        $roles = array_values(array_filter((array) $event->roles));
        if ($ids === [] && $roles === []) {
            return []; // nobody addressed: never fall back to "everyone"
        }

        $query = User::query()
            ->whereHas('deviceTokens')
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
            ->where(function ($q) use ($ids, $roles) {
                if ($ids !== []) {
                    $q->orWhereIn('id', $ids);
                }
                foreach ($roles as $role) {
                    if (str_contains($role, ':')) {
                        [$main, $sub] = explode(':', $role, 2);
                        $q->orWhere(fn ($w) => $w->where('role', $main)->where('sub_role', $sub));
                    } else {
                        $q->orWhere('role', $role);
                    }
                }
            });

        if ($event->actor_id) {
            $query->where('id', '!=', $event->actor_id);
        }

        return $query->with('deviceTokens')->get()
            ->flatMap(fn (User $u) => $u->deviceTokens->pluck('token'))
            ->unique()
            ->values()
            ->all();
    }
}
