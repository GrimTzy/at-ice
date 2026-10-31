<?php

namespace App\Providers;

use App\Models\User;
use App\Support\LiveFeed;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    // Register any application services.

    public function register(): void
    {
        //
    }

    // Bootstrap any application services.
    public function boot(): void
    {
        // Realtime pop-up notices for every flow (see App\Support\LiveFeed)
        foreach (LiveFeed::models() as $model) {
            $model::created(fn ($m) => LiveFeed::record($m, 'created'));
            $model::updated(fn ($m) => LiveFeed::record($m, 'updated'));
            $model::deleted(fn ($m) => LiveFeed::record($m, 'deleted'));
        }

        // A token of a deactivated account stops working on every protected
        // route (auth:sanctum), even one issued before the account was
        // deactivated. The token is revoked, and the request gets the usual
        // 401, so the browser ends the session and shows the login page.
        Sanctum::authenticateAccessTokensUsing(function ($token, bool $isValid) {
            if (! $isValid) {
                return false;
            }
            $user = $token->tokenable;
            if ($user instanceof User && $user->isDeactivated()) {
                $token->delete();

                return false;
            }

            return true;
        });

        // Email fields use "email:rfc,filter,dns": the domain after @ must really
        // exist (a real extension like .com or .ph, and a mail or web server), so
        // typos such as "gmail.comsdada" or "name@gmail" are refused.
        // Load Laravel's own validation messages first: addLines() alone would
        // mark the group as loaded and every other message would show as a
        // raw key such as "validation.string".
        $translator = $this->app['translator'];
        $translator->load('*', 'validation', 'en');
        $translator->addLines([
            'validation.email' => 'Enter a real email address. The part after @ must be an existing domain, e.g. name@gmail.com.',
        ], 'en');
    }
}
