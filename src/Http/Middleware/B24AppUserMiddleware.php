<?php

namespace X3Group\Bitrix24\Http\Middleware;

use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;
use X3Group\Bitrix24\Models\B24User;

class B24AppUserMiddleware
{
    public function handle(Request $request, \Closure $next)
    {
        $memberId = $request->post('member_id');
        if (empty($memberId)) {
            return response()->json(['error' => 'memberId is null'], 406);
        }
        $reLogin = false;
        if (!auth()->check()) {
            $reLogin = true;
        } elseif ((auth()->user()->getMemberId() != $memberId)) {
            $reLogin = true;
        } else {
            if (is_null(auth()->user()->expires) || time() >= auth()->user()->expires) {
                $reLogin = true;
            }
        }

        if ($reLogin) {
            if (!$request->post('AUTH_ID'))
                return response()->json(['error' => 'AUTH_ID is null'], 406);

            try {
                $b24 = ServiceBuilderFactory::createServiceBuilderFromPlacementRequest(
                    placementRequest: $request,
                    applicationProfile: new ApplicationProfile(
                        clientId: config('bitrix24.client_id'),
                        clientSecret: config('bitrix24.client_secret'),
                        scope: Scope::initFromString(config('bitrix24.scope'))
                    ),
                    eventDispatcher: new EventDispatcherAdapter(),
                    logger: resolve('b24log', [
                        'memberId' => $memberId,
                        'domain' => $request->input('DOMAIN'),
                    ]),
                );

                $profile = $b24->getMainScope()->main()->getCurrentUserProfile()->getUserProfile();

                $userFind = B24User::query()
                    ->where('user_id', $profile->ID)
                    ->where('member_id', $memberId)
                    ->first();

                if ($userFind) {
                    $userFind->update([
                        'access_token' => $request->post('AUTH_ID'),
                        'refresh_token' => $request->post('REFRESH_ID'),
                        'domain' => $request->get('DOMAIN'),
                        'is_admin' => $profile->ADMIN,
                        'expires' => time() + (int)$request->post('AUTH_EXPIRES') - 600,
                        'expires_in' => 3600,
                    ]);
                } else {
                    $userFind = B24User::query()
                        ->create([
                            'user_id' => $profile->ID,
                            'member_id' => $request->post('member_id'),
                            'access_token' => $request->post('AUTH_ID'),
                            'refresh_token' => $request->post('REFRESH_ID'),
                            'application_token' => $request->post('APP_SID'),
                            'domain' => $request->get('DOMAIN'),
                            'is_admin' => $profile->ADMIN,
                            'expires' => time() + (int)$request->post('AUTH_EXPIRES') - 600,
                            'expires_in' => 3600,
                        ]);
                }

                auth()->login($userFind);
                if (!auth()->check()) {
                    return response()->json(['error' => 'Unauthorized, auth failed'], 401);
                }
                Context::addHidden('memberId', $memberId);
                Context::addHidden('userId', $userFind->user_id);
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage()], 401);
            }
        }

        return $next($request);
    }
}
