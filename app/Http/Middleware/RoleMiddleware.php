<?php
// app/Http/Middleware/RoleMiddleware.php

namespace App\Http\Middleware;

use Closure;

class RoleMiddleware
{
    public function handle($request, Closure $next, ...$roles)
    {
        if (!auth()->check()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        
        $userRole = auth()->user()->role;
        
        if (!in_array($userRole, $roles)) {
            return response()->json([
                'error' => 'Unauthorized. Required role: ' . implode(', ', $roles)
            ], 403);
        }
        
        return $next($request);
    }
}