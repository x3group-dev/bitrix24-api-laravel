<?php

namespace X3Group\Bitrix24\Http\Middleware;

use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Scope;
use X3Group\Bitrix24\Core\B24ServiceBuilderFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;
use X3Group\Bitrix24\Application\Local\OauthServerUrlResolver;
use X3Group\Bitrix24\Models\B24App;
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
                $oauthServerUrl = OauthServerUrlResolver::fromServerEndpoint($request->input('SERVER_ENDPOINT'));

                $b24 = B24ServiceBuilderFactory::createServiceBuilderFromPlacementRequest(
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
                    oauthServerUrl: $oauthServerUrl,
                );

                B24App::query()
                    ->where('member_id', $memberId)
                    ->whereNull('oauth_server_url')
                    ->update(['oauth_server_url' => $oauthServerUrl]);

                $profile = $b24->getMainScope()->main()->getCurrentUserProfile()->getUserProfile();

                $userFind = B24User::query()
                    ->where('user_id', $profile->ID)
                    ->where('member_id', $memberId)
                    ->first();

                // Считается один раз на обе строки: тот же токен в b24_users и в b24_apps
                // обязан протухать в один и тот же момент.
                $expires = time() + (int)$request->post('AUTH_EXPIRES') - 600;

                if ($userFind) {
                    $userFind->update([
                        'access_token' => $request->post('AUTH_ID'),
                        'refresh_token' => $request->post('REFRESH_ID'),
                        'domain' => $request->get('DOMAIN'),
                        'is_admin' => $profile->ADMIN,
                        'expires' => $expires,
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
                            'expires' => $expires,
                            'expires_in' => 3600,
                        ]);
                }

                $refreshId = $request->post('REFRESH_ID');

                // Правило 2: если приложение открыл владелец портала, его свежий токен
                // размещения уезжает и в b24_apps. Это основной путь, которым app-токен
                // портала остаётся живым. Кто владелец — решает колонка b24_apps.user_id,
                // а не этот запрос.
                //
                // Профиль без ID до правила 2 не доходит: приведение (int)null дало бы
                // пользователя 0 и notice «обновляет не владелец» там, где на самом деле
                // просто не удалось понять, кто пришёл. Сегодня ветка недостижима
                // (b24_users.user_id объявлен NOT NULL, такой профиль падает выше, на
                // создании строки), её debug-строки в логе не будет — искать бесполезно.
                if ($profile->ID === null) {
                    logger()->debug('b24 app token: propagation skipped (placement user not identified)', [
                        'member_id' => $memberId,
                    ]);
                } elseif ($refreshId === null || $refreshId === '') {
                    // Токен без refresh'а в b24_apps не пишется: b24_apps.refresh_token —
                    // NOT NULL, перенос упал бы QueryException'ом в catch ниже и отдал бы
                    // 401 на всё размещение, причём именно владельцу.
                    logger()->debug('b24 app token: propagation skipped (placement carries no refresh token)', [
                        'member_id' => $memberId,
                        'user_id' => (int)$profile->ID,
                    ]);
                } else {
                    app(AppTokenWriter::class)->propagateFromUser(
                        $memberId,
                        (int)$profile->ID,
                        new AuthToken(
                            accessToken: $request->post('AUTH_ID'),
                            refreshToken: $refreshId,
                            expires: $expires,
                            expiresIn: 3600,
                        ),
                    );
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
