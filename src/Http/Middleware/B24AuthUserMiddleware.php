<?php

namespace X3Group\Bitrix24\Http\Middleware;

use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Scope;
use X3Group\Bitrix24\Core\B24ServiceBuilderFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use X3Group\Bitrix24\Application\Local\OauthServerUrlResolver;
use X3Group\Bitrix24\Models\B24App;
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

        // Четвёртый обязательный заголовок, который раньше не проверялся: он
        // уходит в AuthToken::$expires, а тот объявлен non-nullable int. Без
        // заголовка (или с нечисловым значением) конструктор бросал TypeError,
        // и вместо осмысленного отказа клиент получал 500.
        $expires = $request->header('X-b24api-expires-in');
        if ($expires === null || $expires === '') {
            return response()->json(['error' => 'X-b24api-expires-in is null'], 406);
        }

        if (filter_var($expires, FILTER_VALIDATE_INT) === false) {
            return response()->json(['error' => 'X-b24api-expires-in is not an integer'], 406);
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

                $factory = new B24ServiceBuilderFactory(
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
                    oauthServerUrl: OauthServerUrlResolver::orDefault(
                        B24App::query()->where('member_id', $memberId)->value('oauth_server_url')
                    ),
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
            } catch (\Throwable $e) {
                // \Error (TypeError и родня) — не отказ авторизации, а дефект:
                // прежний catch его не перехватывал, и наружу уходила 500.
                // Клиенту отдаём тот же 401, но без деталей — в сообщении
                // Error лежат пути vendor. Подробности идут в лог.
                Log::error('B24AuthUserMiddleware: ' . $e->getMessage(), [
                    'member_id' => $memberId,
                    'exception' => $e,
                ]);

                return response()->json(['error' => 'authorization failed'], 401);
            }
        }
        return $next($request);
    }
}
