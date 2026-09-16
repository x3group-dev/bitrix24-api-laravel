<?php

namespace X3Group\Bitrix24\Application\SystemUser;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use X3Group\Bitrix24\Events\SystemAppUserAnchored;
use X3Group\Bitrix24\Jobs\GrantSystemUserEntityRightsJob;
use X3Group\Bitrix24\Models\B24App;

/**
 * Событие ONAPPUSERREADY: портал создал системного пользователя приложения и прислал его
 * долгоживущую авторизацию. Переводим app-токен портала на неё и ставим якорь, после
 * которого токен не перезаписывается ничем.
 *
 * Событие приходит POST-запросом на URL УСТАНОВКИ приложения — подписку портал создаёт
 * сам, event.bind не нужен. Развилка ставится в начале install-обработчика приложения:
 *
 *     if (AppUserReadyService::handles($request)) {
 *         return app(AppUserReadyService::class)->handle($request);
 *     }
 *
 * Внутрь InstallService она не прячется: там тип ответа — view, а событию нужен JSON.
 */
class AppUserReadyService
{
    /** Задержка перед раздачей прав: установка ещё может создавать сущности прежним токеном. */
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

        $b24app = B24App::query()->where('member_id', $memberId)->first();
        $applicationToken = (string) ($auth['application_token'] ?? '');

        // Строки портала ещё нет — событие обогнало установку. Сверять не с чем, пишем:
        // то же правило допуска, что у первой установки в AppTokenWriter::shouldWrite().
        if ($b24app !== null) {
            $storedToken = (string) $b24app->application_token;

            if ($storedToken === '') {
                // Старая установка без сохранённого application_token: сверка невозможна,
                // а отказ означал бы, что такой портал не переедет никогда.
                logger()->notice('ONAPPUSERREADY: application_token is not stored', [
                    'member_id' => $memberId,
                ]);
            } elseif (!hash_equals($storedToken, $applicationToken)) {
                logger()->warning('ONAPPUSERREADY rejected: application_token mismatch', [
                    'member_id' => $memberId,
                ]);

                return response()->json(['error' => 'application_token mismatch'], 403);
            }
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
        ];

        $domain = $this->resolveDomain($b24app?->domain, $data, $auth);
        if ($domain !== '') {
            $attributes['domain'] = $domain;
        }

        if (!empty($data['server_endpoint'])) {
            $attributes['oauth_server_url'] = (string) $data['server_endpoint'];
        }

        if ($b24app === null) {
            // domain NOT NULL без default: пустое значение здесь — не редкость, которую
            // стоит ронять в SQL-ошибку, а внятный отказ, как у остальных проверок выше.
            if ($domain === '') {
                logger()->warning('ONAPPUSERREADY rejected: portal domain is unknown', [
                    'member_id' => $memberId,
                ]);

                return response()->json(['error' => 'portal domain is unknown'], 400);
            }

            $attributes['member_id'] = $memberId;
            if ($applicationToken !== '') {
                $attributes['application_token'] = $applicationToken;
            }
            // insert()/update() мимо модели таймстампы не проставляют (в отличие от
            // create()/save() в AppAuthDatabaseStorage) — заполняем их сами.
            $attributes['created_at'] = now();
            $attributes['updated_at'] = now();
            B24App::query()->insert($attributes);
        } else {
            $attributes['updated_at'] = now();
            B24App::query()->where('member_id', $memberId)->update($attributes);
        }

        logger()->info('ONAPPUSERREADY: system user token saved', [
            'member_id' => $memberId,
            'system_user_id' => $systemUserId,
            'first_row' => $b24app === null,
            'domain' => $domain,
            'scope' => $data['scope'] ?? null,
        ]);

        event(new SystemAppUserAnchored($memberId, $systemUserId));

        if (config('bitrix24.system_user.grant_entity_rights', false)) {
            GrantSystemUserEntityRightsJob::dispatch($memberId)
                ->delay(now()->addMinutes(self::RIGHTS_DELAY_MINUTES));
        }

        return response()->json(['result' => true]);
    }

    /**
     * Домен портала. В data.domain лежит домен сервера авторизации, а не портала, поэтому
     * он здесь не используется вовсе: берём сохранённый домен, затем хост из
     * client_endpoint, затем домен установщика из auth.
     */
    private function resolveDomain(?string $storedDomain, array $data, array $auth): string
    {
        if (!empty($storedDomain)) {
            return (string) $storedDomain;
        }

        $host = parse_url((string) ($data['client_endpoint'] ?? ''), PHP_URL_HOST);
        if (!empty($host)) {
            return (string) $host;
        }

        return trim((string) ($auth['domain'] ?? ''));
    }
}
