<?php

namespace X3Group\B24Api\Http\Middleware;

use Illuminate\Http\Request;

class B24AuthApi
{
    public function handle(Request $request, \Closure $next)
    {
        $memberId = $request->header('X-b24-Member-Id');
        if (empty($memberId)) {
            return response()->json(['error' => 'memberId is null'], 406);
        }
        if (auth()->check()) {
            if (auth()->user()->getMemberId() != $memberId) {
                return response()->json(['error' => 'memberId is another b24'], 401);
            }
        } else {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        return $next($request);
    }
}
