<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// QA review 2026-11-12: no page's data request may end in a server error (500),
// whatever role asks, on a fresh system with nothing in it yet. Only reads are
// called; a wrong role gets 403/404, never a crash.
class ReadRoutesSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, ?string $sub = null): User
    {
        $user = User::create(['name' => ucfirst($sub ?: $role), 'email' => ($sub ?: $role).'@atice.cloud', 'password' => 'secret-password', 'role' => $role, 'sub_role' => $sub]);

        return in_array($role, ['employee', 'driver'], true) ? $this->timedIn($user) : $user;
    }

    public function test_no_read_route_crashes_for_any_role_on_an_empty_system(): void
    {
        $roles = [['admin', null], ['hr', null], ['customer', null], ['driver', null], ['employee', 'checker'], ['employee', 'salesman'], ['employee', 'maintenance']];
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/') && in_array('GET', $route->methods(), true)
                && collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth'))) {
                $routes[] = '/'.preg_replace('/\{[^}]+\??\}/', '1', $route->uri());
            }
        }

        $this->assertGreaterThan(100, count($routes)); // the list itself is not empty by mistake

        $crashed = [];
        foreach ($roles as [$role, $sub]) {
            Sanctum::actingAs($this->user($role, $sub));
            foreach ($routes as $path) {
                $status = $this->getJson($path)->getStatusCode();
                if ($status >= 500) {
                    $crashed[] = ($sub ?: $role)." GET {$path} -> {$status}";
                }
            }
        }
        $this->assertSame([], $crashed);
    }
}
