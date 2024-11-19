<?php

namespace X3Group\Bitrix24\Http\Middleware;

use Illuminate\Http\Request;

class B24AppMiddleware
{
    public function handle(Request $request, \Closure $next)
    {
        if ($request->has('member_id') || $request->has('auth') && isset($request->post('auth')['member_id']) && !empty($request->post('auth')['member_id'])) {
            //
        } else {
            return response()->json(['error' => 'memberId is null'], 406);
        }

        return $next($request);
    }
}
