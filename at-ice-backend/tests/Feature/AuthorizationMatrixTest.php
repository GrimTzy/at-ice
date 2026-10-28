<?php

namespace Tests\Feature;

use App\Http\Middleware\RoleMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// QA: every API route is checked against its role middleware, so hiding a menu
// item is never the only protection. Anonymous calls to protected routes must
// be 401; a signed-in user whose role the route does not allow must be 403 and
// never reach the controller (no 2xx, no 5xx).
class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const SUB_ROLES = ['checker', 'salesman', 'maintenance'];

    /** @return array<int, array{route: \Illuminate\Routing\Route, method: string, uri: string, roles: ?array}> */
    private function protectedRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $protected = collect($middleware)->contains(fn ($m) => is_string($m) && str_contains($m, 'auth:sanctum') || str_contains((string) $m, 'Authenticate:sanctum'));
            if (! $protected) {
                continue;
            }
            $roleRule = collect($middleware)->first(fn ($m) => is_string($m) && str_starts_with($m, 'role:'));
            $roles = $roleRule ? explode(',', substr($roleRule, 5)) : null;
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $uri = '/'.preg_replace('/\{[^}]+\??\}/', '1', $route->uri());
            $out[] = ['route' => $route, 'method' => $method, 'uri' => $uri, 'roles' => $roles];
        }

        return $out;
    }

    private function allowed(array $roles, array $user): bool
    {
        if ($user['role'] === 'admin') {
            return true;
        }
        if (count($roles) >= 2 && in_array($roles[1], self::SUB_ROLES, true)) {
            return $user['role'] === $roles[0] && in_array($user['sub_role'], array_slice($roles, 1), true);
        }

        return in_array($user['role'], $roles, true);
    }

    public function test_anonymous_calls_to_protected_routes_are_401(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $routes = $this->protectedRoutes();
        $this->assertGreaterThan(150, count($routes), 'route discovery found too few protected routes');

        $bad = [];
        foreach ($routes as $r) {
            $status = $this->json($r['method'], $r['uri'])->getStatusCode();
            if ($status !== 401) {
                $bad[] = "{$r['method']} {$r['uri']} -> $status";
            }
        }
        $this->assertSame([], $bad, "Protected routes that answered an anonymous caller:\n".implode("\n", $bad));
    }

    public function test_every_role_is_blocked_from_routes_it_is_not_allowed_to_use(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $users = [
            ['role' => 'hr', 'sub_role' => null],
            ['role' => 'employee', 'sub_role' => 'checker'],
            ['role' => 'employee', 'sub_role' => 'salesman'],
            ['role' => 'employee', 'sub_role' => 'maintenance'],
            ['role' => 'driver', 'sub_role' => null],
            ['role' => 'customer', 'sub_role' => null],
        ];

        $bad = [];
        $checked = 0;
        foreach ($users as $spec) {
            Sanctum::actingAs(User::factory()->create($spec + ['is_active' => true]));
            foreach ($this->protectedRoutes() as $r) {
                if ($r['roles'] === null || $this->allowed($r['roles'], $spec)) {
                    continue;
                }
                $checked++;
                $status = $this->json($r['method'], $r['uri'])->getStatusCode();
                if ($status !== 403) {
                    $bad[] = "{$spec['role']}/{$spec['sub_role']} {$r['method']} {$r['uri']} -> $status";
                }
            }
        }
        $this->assertGreaterThan(500, $checked);
        $this->assertSame([], $bad, "Role-restricted routes reachable by the wrong role:\n".implode("\n", $bad));
    }
}
