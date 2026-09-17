<?php

namespace X3Group\Bitrix24\Application\SystemUser;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use X3Group\Bitrix24\Events\SystemAppUserAnchored;
use X3Group\Bitrix24\Jobs\GrantSystemUserEntityRightsJob;
use X3Group\Bitrix24\Models\B24App;

/**
 * Событие ONAPPUSERREADY: портал создал системного пользователя приложения и прислал его
 * долгоживущую авторизацию. Переводим app-токен портала на неё и включаем флаг
 * is_system_user, после которого токены доступа не перезаписываются установкой и
 * ремонтными командами.
 *
 * Запись разрешена только порталу, доказавшему подлинность: строка b24_apps существует,
 * в ней сохранён непустой application_token и он совпал с присланным. Иначе — 403.
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

        $applicationToken = (string) ($auth['application_token'] ?? '');

        // Токен администратора и адрес портала, снятые со строки до её перезаписи. Ими
        // выдаются права системному пользователю — у него самого прав на это может не быть.
        $grant = [];

        $saved = DB::transaction(function () use ($memberId, $applicationToken, $data, $accessToken, $refreshToken, $systemUserId, &$grant): ?string {
            // Строка читается под блокировкой и обновляется в той же транзакции: событие
            // приходит в момент установки, то есть параллельная запись — штатный сценарий.
            $b24app = B24App::query()->where('member_id', $memberId)->lockForUpdate()->first();

            if ($b24app === null) {
                return 'portal is not installed';
            }

            $storedToken = (string) $b24app->application_token;

            if ($storedToken === '') {
                return 'application_token is not stored';
            }

            if (!hash_equals($storedToken, $applicationToken)) {
                return 'application_token mismatch';
            }

            $expiresIn = (int) ($data['expires_in'] ?? 0);
            if ($expiresIn <= 0) {
                // Та же конвенция, что в AppAuthDatabaseStorage, Bitrix24App::renewTokens
                // и AppTokenWriter: менять её нужно везде сразу.
                $expiresIn = 3600;
            }

            $attributes = [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires' => time() + $expiresIn,
                'expires_in' => $expiresIn,
                'user_id' => $systemUserId,
                'is_system_user' => true,
                'error_update' => 0,
                // update() мимо модели таймстампы не проставляет.
                'updated_at' => now(),
            ];

            $domain = $this->resolveDomain($b24app->domain, $data);
            if ($domain !== '') {
                $attributes['domain'] = $domain;
            }

            if (!empty($data['server_endpoint'])) {
                $attributes['oauth_server_url'] = (string) $data['server_endpoint'];
            }

            $grant = [
                'access_token' => (string) $b24app->access_token,
                'domain' => (string) ($attributes['domain'] ?? $b24app->domain),
                'oauth_server_url' => $attributes['oauth_server_url'] ?? $b24app->oauth_server_url,
            ];

            B24App::query()->where('member_id', $memberId)->update($attributes);

            return null;
        });

        if ($saved !== null) {
            logger()->warning('ONAPPUSERREADY rejected: ' . $saved, [
                'member_id' => $memberId,
            ]);

            return response()->json(['error' => $saved], 403);
        }

        logger()->info('ONAPPUSERREADY: system user token saved', [
            'member_id' => $memberId,
            'system_user_id' => $systemUserId,
            'scope' => $data['scope'] ?? null,
        ]);

        event(new SystemAppUserAnchored($memberId, $systemUserId));

        if (config('bitrix24.system_user.grant_entity_rights', false)) {
            GrantSystemUserEntityRightsJob::dispatch(
                $memberId,
                $grant['access_token'],
                $grant['domain'],
                $grant['oauth_server_url'],
            )->delay(now()->addMinutes(self::RIGHTS_DELAY_MINUTES));
        }

        return response()->json(['result' => true]);
    }

    /**
     * Домен портала: сохранённый, иначе хост из client_endpoint. В data.domain лежит домен
     * сервера авторизации, а не портала, поэтому он не используется вовсе.
     */
    private function resolveDomain(?string $storedDomain, array $data): string
    {
        if (!empty($storedDomain)) {
            return (string) $storedDomain;
        }

        return (string) (parse_url((string) ($data['client_endpoint'] ?? ''), PHP_URL_HOST) ?: '');
    }
}
