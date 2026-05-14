<?php
// app/Http/Middleware/RateLimitMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RateLimitMiddleware
{
    protected $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    public function handle(Request $request, Closure $next, $maxAttempts = 60, $decayMinutes = 1)
    {
        $key = $this->resolveRequestSignature($request);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);
            
            return response()->json([
                'error' => 'Too many requests. Please try again later.',
                'retry_after' => $retryAfter
            ], 429);
        }

        $this->limiter->hit($key, $decayMinutes * 60);

        $response = $next($request);

        return $this->addHeaders(
            $response, 
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts, $this->limiter->attempts($key)),
            $retryAfter ?? null
        );
    }

    protected function resolveRequestSignature($request)
    {
        return sha1(
            $request->method() .
            '|' . $request->url() .
            '|' . ($request->user() ? $request->user()->id : $request->ip())
        );
    }

    protected function addHeaders($response, $maxAttempts, $remainingAttempts, $retryAfter = null)
    {
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', $remainingAttempts);
        
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', $retryAfter);
        }
        
        return $response;
    }

    protected function calculateRemainingAttempts($key, $maxAttempts, $attempts)
    {
        return $maxAttempts - $attempts + 1;
    }
}