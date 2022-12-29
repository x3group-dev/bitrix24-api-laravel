<?php

namespace X3Group\B24Api\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use X3Group\B24Api\B24ApiUser;
use X3Group\B24Api\Models\B24User;

class B24App
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
