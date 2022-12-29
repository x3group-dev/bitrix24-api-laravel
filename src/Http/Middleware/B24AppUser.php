<?php

namespace X3Group\B24Api\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use X3Group\B24Api\B24ApiUserRequest;
use X3Group\B24Api\Models\B24User;

class B24AppUser
{
    public function handle(Request $request, \Closure $next)
    {
        $memberId = $request->post('member_id');
        if (empty($memberId)) {
            return response()->json(['error' => 'memberId is null'], 406);
        }

        if (!auth()->check() || (auth()->user()->getMemberId() != $memberId)) {
            if (!$request->post('member_id') && !$request->post('AUTH_ID'))
                return response()->json(['error' => 'memberId or AUTH_ID is null'], 406);

            try {
                $api = new B24ApiUserRequest($memberId, $request->post('AUTH_ID'), $request->post('REFRESH_ID'), $request->get('APP_SID'));
                if ($profile = $api->getProfile()) {
                    $userFind = B24User::where('user_id', $profile['ID'])->where('member_id', $request->post('member_id'))->first();
                    if ($userFind) {
                        $userFind->update(
                            [
                                'access_token' => $request->post('AUTH_ID'),
                                'refresh_token' => $request->post('REFRESH_ID'),
                                'application_token' => $request->get('APP_SID'),
                                'domain' => $request->get('DOMAIN'),
                                'is_admin' => $profile['ADMIN']
                            ]
                        );
                    } else {
                        $userData = [
                            'user_id' => $profile['ID'],
                            'password' => Hash::make(Str::random(16)),
                            'member_id' => $request->post('member_id'),
                            'access_token' => $request->post('AUTH_ID'),
                            'refresh_token' => $request->post('REFRESH_ID'),
                            'application_token' => $request->get('APP_SID'),
                            'domain' => $request->get('DOMAIN'),
                            'is_admin' => $profile['ADMIN']
                        ];

                        $user = new B24User;
                        $user->fill($userData);
                        $user->save();
                        $userFind = B24User::find($user->id);
                    }
                    auth()->login($userFind);
                    if (!auth()->check()) {
                        return response()->json(['error' => 'Unauthorized, auth failed'], 401);
                    }
                } else {
                    return response()->json(['error' => 'Unauthorized, user not found'], 401);
                }
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage()], 401);
            }
        }

        return $next($request);
    }
}
