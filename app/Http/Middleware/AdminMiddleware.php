<?php
namespace App\Http\Middleware;

use Closure;

class AdminMiddleware
{
    public function handle($request, Closure $next)
    {
        if (!auth()->check() || auth()->user()->role !== 'admin') {
            return response()->json([
                'error' => 'Unauthorized. Admin access required.'
            ], 403);
        }
        
        return $next($request);
    }
}