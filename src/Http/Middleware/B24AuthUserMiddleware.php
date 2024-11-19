<?php

namespace X3Group\Bitrix24\Http\Middleware;

use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Illuminate\Http\Request;
use X3Group\Bitrix24\Models\B24User;

class B24AuthUserMiddleware
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
                $applicationProfile = new ApplicationProfile(
                    clientId: config('bitrix24.client_id'),
                    clientSecret: config('bitrix24.client_secret'),
                    scope: Scope::initFromString(config('bitrix24.scope'))
                );

                $authToken = new AuthToken(
                    accessToken: $request->header('X-b24api-access-token'),
                    refreshToken: $request->header('X-b24api-refresh-token'),
                    expires: $request->header('X-b24api-expires-in'),
                    expiresIn: 3600,
                );

                $factory = new ServiceBuilderFactory(
                    eventDispatcher: resolve('userEvents', [
                        'memberId' => $memberId,
                    ]),
                    log: resolve('b24log', [
                        'memberId' => $memberId
                    ]),
                );

                $b24 = $factory->init(
                    applicationProfile: $applicationProfile,
                    authToken: $authToken,
                    bitrix24DomainUrl: "https://{$request->header('X-b24api-domain')}",
                );

                $profile = $b24->getUserScope()->user()->current()->user();

                $user = B24User::query()
                    ->where('member_id', $memberId)
                    ->where('user_id', $profile->ID)
                    ->first();

                if (!$user) {
                    throw new \Exception('User not found');
                }

                auth()->login($user);
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage()], 401);
            }
        }
        return $next($request);
    }
}
