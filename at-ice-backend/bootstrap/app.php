<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Private realtime channels (routes/channels.php): the SPA signs each
    // subscription at POST /api/broadcasting/auth with its Sanctum token.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware) {
        // Guests on the API get a JSON 401, never a login redirect (there is no login route).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'password.changed' => \App\Http\Middleware\EnsurePasswordChanged::class,
            'kiosk' => \App\Http\Middleware\EnsureKioskToken::class,
            'timed.in' => \App\Http\Middleware\EnsureTimedIn::class,
            'idle' => \App\Http\Middleware\EnforceIdleTimeout::class,
        ]);
        // Check the role before route model binding, so a wrong role gets 403 on
        // every route, not 404 for ids that do not exist (which showed which ids do).
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, \App\Http\Middleware\RoleMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A missing record or route answers with a plain 404. Laravel's own text names the
        // internal model class and the id ("No query results for model [App\Models\...] 5").
        // Only that lookup text is replaced. An intentional abort(404, '...') message and an
        // unknown route keep Laravel's normal answer, so the API format does not change.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getPrevious() instanceof \Illuminate\Database\Eloquent\ModelNotFoundException && ($request->is('api/*') || $request->expectsJson())) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        });
        // The API always answers in JSON; other requests keep their usual response.
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());
    })->create();
