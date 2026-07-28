<?php

namespace X3Group\Bitrix24\Application\Install;

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Application\Requests\Events\OnApplicationInstall\OnApplicationInstall;
use Bitrix24\SDK\Application\Requests\Events\OnApplicationUninstall\OnApplicationUninstall;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Services\Main\Common\EventHandlerMetadata;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Illuminate\Http\Request;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;
use X3Group\Bitrix24\Application\Local\OauthServerUrlResolver;
use X3Group\Bitrix24\Models\B24App;

/**
 * Централизованный поток установки приложения.
 *
 * Единая точка, отрабатывающая как открытие install-страницы (PLACEMENT install),
 * так и серверное событие ONAPPINSTALL: сохраняет app-токен под admin-gate
 * (см. {@see AppTokenWriter}) и прогоняет зарегистрированные установщики
 * ({@see AppSetupRunner}). Во всех местах прокидывается oauthServerUrl.
 *
 * Владельцем портала может стать только администратор: профиль ставящего запрашивается
 * и проверяется на ОБОИХ путях, иначе установка прерывается
 * {@see InstallerIsNotAdminException} и не пишется ничего. Проверка не сокращается ни
 * для первой установки портала, ни для серверного пути — {@see AppTokenWriter::shouldWrite}
 * (storage-уровень, у него есть другие вызывающие) первую установку не-админом пропускает,
 * так что единственный гейт — здесь.
 *
 * Ошибки НЕ проглатываются — их ловят тонкие стабы-контроллеры.
 */
class InstallService
{
    public function handleInstallPage(Request $request): void
    {
        // Клиент строится из PLACEMENT-запроса, то есть несёт токен ОТКРЫВШЕГО страницу,
        // а не портала. Дать ему шину 'appEvents' значит взвести слушателя «записать
        // обновлённый токен в b24_apps»: если токен открывшего протух и обновился прямо
        // на вызове getCurrentUserProfile() ниже, его токен уехал бы в b24_apps мимо
        // AppTokenWriter — то есть мимо и admin-gate'а, и записи владельца. Изоляция шин
        // тут не спасает: слушатель и клиент — один и тот же экземпляр. Поэтому шина
        // сознательно БЕЗ слушателей; app-токен пишется ниже явным вызовом.
        $eventDispatcher = new EventDispatcherAdapter();

        $oauthServerUrl = OauthServerUrlResolver::fromServerEndpoint($request->input('SERVER_ENDPOINT'));

        $b24 = ServiceBuilderFactory::createServiceBuilderFromPlacementRequest(
            placementRequest: $request,
            applicationProfile: new ApplicationProfile(
                clientId: config('bitrix24.client_id'),
                clientSecret: config('bitrix24.client_secret'),
                scope: Scope::initFromString(config('bitrix24.scope')),
            ),
            eventDispatcher: $eventDispatcher,
            logger: resolve('b24log', [
                'memberId' => $request->input('member_id'),
                'domain' => $request->input('DOMAIN'),
            ]),
            oauthServerUrl: $oauthServerUrl,
        );

        $profile = $b24->getMainScope()
            ->main()
            ->getCurrentUserProfile()
            ->getUserProfile();

        $isAdmin = (bool)$profile->ADMIN;
        $userId = $profile->ID;

        if (!$isAdmin) {
            throw new InstallerIsNotAdminException($request->input('member_id'), $userId);
        }

        $localAppAuth = new LocalAppAuth(
            authToken: new AuthToken(
                accessToken: $request->input('AUTH_ID'),
                refreshToken: $request->input('REFRESH_ID'),
                expires: (int)$request->input('AUTH_EXPIRES'),
            ),
            domainUrl: $request->input('DOMAIN'),
            applicationToken: null,
            oauthServerUrl: $oauthServerUrl,
        );

        app(AppTokenWriter::class)->saveIfAllowed($localAppAuth, $request->input('member_id'), $isAdmin, $userId);

        $b24->getMainScope()->eventManager()->unbindAllEventHandlers();

        $b24->getMainScope()->eventManager()->bindEventHandlers([
            new EventHandlerMetadata(
                code: OnApplicationInstall::CODE,
                handlerUrl: config('app.url') . '/events/onApplicationInstall',
                userId: $userId,
            ),
            new EventHandlerMetadata(
                code: OnApplicationUninstall::CODE,
                handlerUrl: config('app.url') . '/events/onApplicationUninstall',
                userId: $userId,
            ),
        ]);

        app(AppSetupRunner::class)->run($b24, config('bitrix24.installers', []));
    }

    public function handleOnAppInstall(Request $request): void
    {
        $auth = $request->input('auth');

        $oauthServerUrl = OauthServerUrlResolver::orDefault(
            B24App::query()->where('member_id', $auth['member_id'])->value('oauth_server_url')
        );

        $applicationProfile = new ApplicationProfile(
            clientId: config('bitrix24.client_id'),
            clientSecret: config('bitrix24.client_secret'),
            scope: Scope::initFromString(config('bitrix24.scope')),
        );

        // Здесь шина 'appEvents' оставлена сознательно: токен из ONAPPINSTALL — это ровно
        // тот токен, который строкой ниже и станет app-токеном портала. Его рефреш надо
        // сохранить, иначе после установщиков ({@see AppSetupRunner}, десятки REST-вызовов)
        // в b24_apps останется уже отозванный refresh_token и портал сломается.
        // На install-странице такого аргумента нет — там клиент несёт токен ЧУЖОГО
        // (открывшего страницу) пользователя, поэтому шина там без слушателей.
        // Остаточное окно: рефреш во время getCurrentUserProfile() ниже, то есть ДО
        // admin-gate'а, всё ещё запишется в b24_apps мимо AppTokenWriter. Окно узкое
        // (токен выдан Битриксом секунды назад), но не нулевое — см. отчёт по задаче.
        $factory = new ServiceBuilderFactory(
            eventDispatcher: resolve('appEvents'),
            log: resolve('b24log', [
                'memberId' => $auth['member_id'],
            ]),
        );

        $b24 = $factory->init(
            applicationProfile: $applicationProfile,
            authToken: new AuthToken(
                accessToken: $auth['access_token'],
                refreshToken: $auth['refresh_token'],
                expires: (int)($auth['expires_in'] ?? 3600),
            ),
            bitrix24DomainUrl: "https://" . $auth['domain'],
            oauthServerUrl: $oauthServerUrl,
        );

        $profile = $b24->getMainScope()
            ->main()
            ->getCurrentUserProfile()
            ->getUserProfile();

        $isAdmin = (bool)$profile->ADMIN;
        $userId = $profile->ID;

        // Проверка не пропускается и на серверном пути: ONAPPINSTALL приходит с токеном
        // того, кто ставил приложение, и рядовой сотрудник может поставить его так же,
        // как и через install-страницу. Лишний REST-вызов дешевле молча назначенного
        // не-админского владельца портала.
        if (!$isAdmin) {
            throw new InstallerIsNotAdminException($auth['member_id'], $userId);
        }

        $localAppAuth = new LocalAppAuth(
            authToken: new AuthToken(
                accessToken: $auth['access_token'],
                refreshToken: $auth['refresh_token'],
                expires: (int)($auth['expires_in'] ?? 3600),
            ),
            domainUrl: $auth['domain'],
            applicationToken: $auth['application_token'] ?? null,
            oauthServerUrl: $oauthServerUrl,
        );

        app(AppTokenWriter::class)->saveIfAllowed($localAppAuth, $auth['member_id'], $isAdmin, $userId);

        app(AppSetupRunner::class)->run($b24, config('bitrix24.installers', []));
    }
}
