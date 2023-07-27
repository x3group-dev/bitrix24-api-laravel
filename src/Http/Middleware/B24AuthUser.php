<?php

namespace X3Group\B24Api\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use X3Group\B24Api\B24ApiUserRequest;
use X3Group\B24Api\Models\B24User;

class B24AuthUser
{
    public function handle(Request $request, \Closure $next)
    {
        $memberId = $request->header('X-b24api-member-id');
        if (empty($memberId)) {
            return response()->json(['error' => 'memberId is null'], 406);
        }

        $domain = $request->header('X-b24api-domain');
        if (empty($domain)) {
            return response()->json(['error' => 'domain is null'], 406);
        }

        $accessToken = $request->header('X-b24api-access-token');
        if (empty($accessToken)) {
            return response()->json(['error' => 'access token is null'], 406);
        }

        if (!auth()->check() || (auth()->user()->getMemberId() != $memberId)) {
            try {
                $api = new B24ApiUserRequest($memberId, $accessToken, '', '');
                if ($profile = $api->getProfile()) {
                    $userFind = B24User::where('user_id', $profile['ID'])->where('member_id', $request->post('member_id'))->first();
                    if ($userFind) {
                        //todo: надо подумать над refresh, возможно не стоит обновлять access
                        $userFind->update(
                            [
                                'access_token' => $accessToken,
                                'is_admin' => $profile['ADMIN']
                            ]
                        );
                    } else {
                        $api->getApi()->getConfig()->getCredential()->getAccessToken();
                        $userData = [
                            'user_id' => $profile['ID'],
                            'password' => Hash::make(Str::random(16)),
                            'member_id' => $memberId,
                            'access_token' => $accessToken,
                            'refresh_token' => '',
                            'application_token' => '', //todo: взять из родителя
                            'domain' => $domain,
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
