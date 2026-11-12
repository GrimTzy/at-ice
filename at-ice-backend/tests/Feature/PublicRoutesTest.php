<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// QA review 2026-11-12: only the routes that must be public are public. A new
// route outside this list has to be behind a login (or this list changed on
// purpose), and every protected route answers 401 to a visitor.
class PublicRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC = [
        'POST api/login', 'POST api/forgot-password', 'POST api/verify-reset-code', 'POST api/reset-password',
        'POST api/apply',
        // the Attendance Station: unlock/override by PIN, the rest by kiosk token
        'POST api/kiosk/unlock', 'GET api/kiosk/lock-state', 'POST api/kiosk/override',
        'GET api/kiosk/status', 'GET api/face-descriptors', 'POST api/attendances',
    ];

    private function apiRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = [$method, $route->uri(), $route->gatherMiddleware()];
            }
        }

        return $out;
    }

    public function test_only_the_expected_routes_are_open_to_a_visitor(): void
    {
        $open = [];
        foreach ($this->apiRoutes() as [$method, $uri, $middleware]) {
            $guarded = collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth'));
            if (! $guarded) {
                $open[] = "{$method} {$uri}";
            }
        }
        sort($open);
        $expected = self::PUBLIC;
        sort($expected);
        $this->assertSame($expected, $open);
    }

    public function test_every_protected_route_answers_401_to_a_visitor(): void
    {
        $wrong = [];
        foreach ($this->apiRoutes() as [$method, $uri, $middleware]) {
            if (in_array("{$method} {$uri}", self::PUBLIC, true)) {
                continue;
            }
            $status = $this->json($method, '/'.preg_replace('/\{[^}]+\??\}/', '1', $uri))->getStatusCode();
            if ($status !== 401) {
                $wrong[] = "{$method} {$uri} -> {$status}";
            }
        }
        $this->assertSame([], $wrong);
    }
}
