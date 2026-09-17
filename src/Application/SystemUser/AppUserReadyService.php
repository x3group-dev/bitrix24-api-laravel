<?php

namespace X3Group\Bitrix24\Application\SystemUser;

use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Exceptions\TransportException as SdkTransportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;
use X3Group\Bitrix24\Application\Local\OauthServerUrlResolver;
use X3Group\Bitrix24\Events\SystemAppUserAnchored;
use X3Group\Bitrix24\Jobs\GrantSystemUserEntityRightsJob;
use X3Group\Bitrix24\Models\B24App;

/**
 * Событие ONAPPUSERREADY: портал создал системного пользователя приложения и прислал его
 * долгоживущую авторизацию. Переводим app-токен портала на неё и включаем флаг
 * is_system_user, после которого токены доступа не перезаписываются установкой и
 * ремонтными командами.
 *
 * Подлинность события доказывается обменом присланного refresh-токена на сервере OAuth:
 * обменять его может только владелец пары client_id/client_secret приложения. Ответ обмена
 * содержит member_id, который сверяется с заявленным в событии. В b24_apps записываются
 * токены ИЗ ОТВЕТА — присланные в событии после обмена отозваны Битриксом.
 *
 * Событие приходит POST-запросом на URL УСТАНОВКИ приложения — подписку портал создаёт
 * сам, event.bind не нужен. Проверка ставится в начале install-обработчика приложения:
 *
 *     if (AppUserReadyService::handles($request)) {
 *         return app(AppUserReadyService::class)->handle($request);
 *     }
 *
 * Внутрь InstallService она не переносится: там тип ответа — view, а событию нужен JSON.
 */
class AppUserReadyService
{
    /** Задержка перед выдачей прав: установка ещё может создавать сущности прежним токеном. */
    private const RIGHTS_DELAY_MINUTES = 2;

    public static function handles(Request $request): bool
    {
        return $request->post('event') === 'ONAPPUSERREADY';
    }

    public function handle(Request $request): JsonResponse
    {
        $data = (array) $request->post('data');
        $auth = (array) $request->post('auth');

        $memberId = trim((string) ($data['member_id'] ?? ''));
        $authMemberId = trim((string) ($auth['member_id'] ?? ''));

        if ($memberId === '') {
            logger()->warning('ONAPPUSERREADY rejected: member_id is missing');

            return response()->json(['error' => 'member_id is missing'], 400);
        }

        if ($authMemberId !== '' && $authMemberId !== $memberId) {
            logger()->warning('ONAPPUSERREADY rejected: member_id mismatch', [
                'member_id' => $memberId,
                'auth_member_id' => $authMemberId,
            ]);

            return response()->json(['error' => 'member_id mismatch'], 403);
        }

        $accessToken = (string) ($data['access_token'] ?? '');
        $refreshToken = (string) ($data['refresh_token'] ?? '');
        $systemUserId = (int) ($data['user_id'] ?? 0);

        if ($accessToken === '' || $refreshToken === '' || $systemUserId <= 0) {
            logger()->warning('ONAPPUSERREADY rejected: incomplete payload', [
                'member_id' => $memberId,
                'has_access_token' => $accessToken !== '',
                'has_refresh_token' => $refreshToken !== '',
                'system_user_id' => $systemUserId,
            ]);

            return response()->json(['error' => 'incomplete payload'], 400);
        }

        $existing = B24App::query()->where('member_id', $memberId)->first();

        // Повторная доставка события: refresh-токен уже обменян, второй обмен получил бы
        // отказ. Портал уже переведён на этого пользователя — делать нечего.
        if ($existing !== null && (bool) $existing->is_system_user && (int) $existing->user_id === $systemUserId) {
            logger()->info('ONAPPUSERREADY: already switched to this system user', [
                'member_id' => $memberId,
                'system_user_id' => $systemUserId,
            ]);

            return response()->json(['result' => true]);
        }

        $portalHost = (string) parse_url((string) ($data['client_endpoint'] ?? ''), PHP_URL_HOST);
        if ($portalHost === '') {
            logger()->warning('ONAPPUSERREADY rejected: client_endpoint has no host', [
                'member_id' => $memberId,
            ]);

            return response()->json(['error' => 'client_endpoint has no host'], 400);
        }

        // Адрес сервера OAuth берётся из своей строки или из значения по умолчанию.
        // data.server_endpoint из события не используется: обмен на чужом хосте отдал бы
        // client_id и client_secret приложения.
        $oauthServerUrl = OauthServerUrlResolver::orDefault($existing?->oauth_server_url);

        $expiresIn = (int) ($data['expires_in'] ?? 0);
        if ($expiresIn <= 0) {
            $expiresIn = 3600;
        }

        try {
            $renewed = app(SystemUserTokenExchange::class)->exchange(
                $memberId,
                new AuthToken($accessToken, $refreshToken, time() + $expiresIn, $expiresIn),
                'https://' . $portalHost,
                $oauthServerUrl,
            );
        // SdkTransportException приходит и на неизвестный код ошибки в ответе 400: повтор
        // доставки события безопаснее окончательного отказа на временной неполадке.
        } catch (TransportExceptionInterface | SdkTransportException $exception) {
            logger()->warning('ONAPPUSERREADY: oauth exchange transport failure', [
                'member_id' => $memberId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'oauth exchange unavailable'], 503);
        } catch (Throwable $exception) {
            logger()->warning('ONAPPUSERREADY rejected: oauth exchange refused', [
                'member_id' => $memberId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return response()->json(['error' => 'oauth exchange refused'], 403);
        }

        if (!hash_equals($memberId, (string) $renewed->memberId)) {
            logger()->warning('ONAPPUSERREADY rejected: member_id not confirmed by oauth server', [
                'member_id' => $memberId,
                'confirmed_member_id' => $renewed->memberId,
            ]);

            return response()->json(['error' => 'member_id not confirmed'], 403);
        }

        // Адрес портала — хост из client_endpoint ответа. Поле domain ответа — хост
        // сервера OAuth, не портала.
        $confirmedHost = (string) parse_url((string) $renewed->clientEndpoint, PHP_URL_HOST);

        $attributes = [
            'access_token' => $renewed->authToken->accessToken,
            'refresh_token' => $renewed->authToken->refreshToken,
            'expires' => $renewed->authToken->expires,
            'expires_in' => $renewed->authToken->expiresIn ?? 3600,
            'user_id' => $systemUserId,
            'is_system_user' => true,
            'error_update' => 0,
            'oauth_server_url' => OauthServerUrlResolver::fromServerEndpoint($renewed->serverEndpoint),
            // update() мимо модели таймстампы не проставляет.
            'updated_at' => now(),
        ];
        if ($confirmedHost !== '') {
            $attributes['domain'] = $confirmedHost;
        }

        $adminAuth = null;

        DB::transaction(function () use ($memberId, $attributes, &$adminAuth): void {
            // Строка читается под блокировкой и обновляется в той же транзакции: событие
            // приходит в момент установки, то есть параллельная запись — штатный сценарий.
            $b24app = B24App::query()->where('member_id', $memberId)->lockForUpdate()->first();

            if ($b24app === null) {
                $attributes['member_id'] = $memberId;
                $attributes['created_at'] = now();
                B24App::query()->insert($attributes);

                return;
            }

            // Токен администратора снимается до перезаписи: им выдаются права системному
            // пользователю, у которого прав на это может не быть.
            if ((string) $b24app->access_token !== '') {
                $adminAuth = [
                    'access_token' => (string) $b24app->access_token,
                    'domain' => (string) ($attributes['domain'] ?? $b24app->domain),
                    'oauth_server_url' => $attributes['oauth_server_url'],
                ];
            }

            B24App::query()->where('member_id', $memberId)->update($attributes);
        });

        logger()->info('ONAPPUSERREADY: system user token saved', [
            'member_id' => $memberId,
            'system_user_id' => $systemUserId,
            'first_row' => $existing === null,
            'scope' => $data['scope'] ?? null,
        ]);

        event(new SystemAppUserAnchored($memberId, $systemUserId));

        if (config('bitrix24.system_user.grant_entity_rights', false)) {
            if ($adminAuth === null) {
                // Строки не было: сущности создаст установка уже под системным
                // пользователем, выдавать права некому и не за что.
                logger()->info('ONAPPUSERREADY: rights job skipped, no administrator token', [
                    'member_id' => $memberId,
                ]);
            } else {
                GrantSystemUserEntityRightsJob::dispatch(
                    $memberId,
                    $adminAuth['access_token'],
                    $adminAuth['domain'],
                    $adminAuth['oauth_server_url'],
                )->delay(now()->addMinutes(self::RIGHTS_DELAY_MINUTES));
            }
        }

        return response()->json(['result' => true]);
    }
}
