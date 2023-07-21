<?php

namespace X3Group\B24Api\Http\Middleware;

use Illuminate\Http\Request;

class B24AuthApp
{
    public function handle(Request $request, \Closure $next)
    {
        if (!auth()->check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        return $next($request);
    }
}
