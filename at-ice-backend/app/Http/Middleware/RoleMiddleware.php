<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Usage in routes:
     *   ->middleware('role:admin')
     *   ->middleware('role:hr')
     *   ->middleware('role:employee')
     *   ->middleware('role:driver')
     *   ->middleware('role:customer')
     *   ->middleware('role:employee,checker')      ← employee + sub_role
     *   ->middleware('role:employee,salesman')
     *   ->middleware('role:employee,maintenance')
     *   ->middleware('role:admin,hr')              ← multiple roles allowed
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // ── Single sub_role check (e.g. 'role:employee,checker') ──
        // If second param looks like a sub_role, treat it as sub_role filter
        $knownSubRoles = ['checker', 'salesman', 'maintenance'];

        if (count($roles) === 2 && in_array($roles[1], $knownSubRoles)) {
            $requiredRole    = $roles[0]; // 'employee'
            $requiredSubRole = $roles[1]; // 'checker' | 'salesman' | 'maintenance'

            if ($user->role === $requiredRole && $user->sub_role === $requiredSubRole) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Forbidden. Insufficient permissions.',
            ], 403);
        }

        // ── Multi-role check (e.g. 'role:admin,hr') ──
        // Admin can always access HR and Employee routes too
        if (in_array($user->role, $roles)) {
            return $next($request);
        }

        // ── Admin override — Admin can access everything ──
        if ($user->role === 'admin') {
            return $next($request);
        }

        return response()->json([
            'message' => 'Forbidden. Insufficient permissions.',
        ], 403);
    }
}