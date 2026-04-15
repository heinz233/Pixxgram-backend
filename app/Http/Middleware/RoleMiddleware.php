<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RoleMiddleware
 *
 * Checks that the authenticated user's role name matches one of the
 * allowed roles passed as middleware parameters.
 *
 * Usage in routes:  ->middleware('role:admin')
 *                   ->middleware('role:photographer,admin')
 *
 * Registration: add to the $middlewareAliases array in bootstrap/app.php
 *   'role' => \App\Http\Middleware\RoleMiddleware::class,
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $user->loadMissing('role');

        if (!in_array($user->role?->name, $roles)) {
            return response()->json([
                'error' => 'Forbidden. Required role(s): ' . implode(', ', $roles),
            ], 403);
        }

        return $next($request);
    }
}
