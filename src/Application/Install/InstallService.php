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
 * Владельцем портала может стать только опознанный администратор: профиль ставящего
 * запрашивается и проверяется на ОБОИХ путях, иначе установка прерывается
 * {@see InstallerCannotOwnPortalException} и не пишется ничего. Проверка не сокращается
 * ни для первой установки портала, ни для серверного пути — {@see AppTokenWriter::shouldWrite}
 * (storage-уровень, у него есть другие вызывающие) первую установку не-админом пропускает,
 * так что единственный гейт — здесь.
 *
 * Оба пути устроены одинаково и в два клиента:
 *
 *   1. ПРОБА — «кто ставит?». Шина {@see InstallTokenProbe}, без слушателей, пишущих в БД:
 *      до гейта неизвестно, имеет ли ставящий право на портал, поэтому его токен не должен
 *      уметь попасть в b24_apps сам собой. Обновлённый токен проба запоминает в памяти.
 *   2. РАБОЧИЙ клиент — всё, что после гейта: привязка обработчиков событий и установщики.
 *      Шина 'appEvents': к этому моменту токен уже признан токеном портала, и его рефреш
 *      на длинном хвосте установки обязан сохраниться, иначе в b24_apps останется
 *      отозванный refresh_token и портал сломается до переустановки.
 *
 * Ошибки НЕ проглатываются — их ловят тонкие стабы-контроллеры.
 */
class InstallService
{
    public function handleInstallPage(Request $request): void
    {
        $oauthServerUrl = OauthServerUrlResolver::fromServerEndpoint($request->input('SERVER_ENDPOINT'));
        $memberId = $request->input('member_id');

        // Домен берётся из query ровно так же, как его берёт ниже
        // createServiceBuilderFromPlacementRequest, и ровно одним способом на весь метод.
        // input() предпочёл бы тело POST — и если Битрикс когда-нибудь пришлёт DOMAIN там,
        // в b24_apps.domain (постоянная идентичность портала) уехало бы одно значение, а
        // оба клиента ходили бы в другое.
        $domainUrl = trim((string)$request->query->get('DOMAIN'));

        $applicationProfile = new ApplicationProfile(
            clientId: config('bitrix24.client_id'),
            clientSecret: config('bitrix24.client_secret'),
            scope: Scope::initFromString(config('bitrix24.scope')),
        );

        $logger = resolve('b24log', [
            'memberId' => $memberId,
            'domain' => $domainUrl,
        ]);

        $probe = new InstallTokenProbe();

        $b24probe = ServiceBuilderFactory::createServiceBuilderFromPlacementRequest(
            placementRequest: $request,
            applicationProfile: $applicationProfile,
            eventDispatcher: $probe->eventDispatcher(),
            logger: $logger,
            oauthServerUrl: $oauthServerUrl,
        );

        $profile = $b24probe->getMainScope()
            ->main()
            ->getCurrentUserProfile()
            ->getUserProfile();

        $isAdmin = (bool)$profile->ADMIN;
        $userId = $profile->ID;

        if (!$isAdmin) {
            throw InstallerCannotOwnPortalException::notAnAdmin($memberId, $userId);
        }

        if ($userId === null) {
            throw InstallerCannotOwnPortalException::notIdentified($memberId);
        }

        $requestToken = new AuthToken(
            accessToken: $request->input('AUTH_ID'),
            refreshToken: $request->input('REFRESH_ID'),
            expires: (int)$request->input('AUTH_EXPIRES'),
        );

        $localAppAuth = new LocalAppAuth(
            authToken: $probe->tokenForStorage($requestToken),
            domainUrl: $domainUrl,
            applicationToken: null,
            oauthServerUrl: $oauthServerUrl,
        );

        app(AppTokenWriter::class)->saveIfAllowed($localAppAuth, $memberId, $isAdmin, $userId);

        $b24 = (new ServiceBuilderFactory(eventDispatcher: resolve('appEvents'), log: $logger))
            ->init(
                applicationProfile: $applicationProfile,
                authToken: $probe->tokenForClient($requestToken),
                bitrix24DomainUrl: $domainUrl,
                oauthServerUrl: $oauthServerUrl,
            );

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
        $memberId = $auth['member_id'];

        $oauthServerUrl = OauthServerUrlResolver::orDefault(
            B24App::query()->where('member_id', $memberId)->value('oauth_server_url')
        );

        $applicationProfile = new ApplicationProfile(
            clientId: config('bitrix24.client_id'),
            clientSecret: config('bitrix24.client_secret'),
            scope: Scope::initFromString(config('bitrix24.scope')),
        );

        $logger = resolve('b24log', [
            'memberId' => $memberId,
        ]);

        $requestToken = new AuthToken(
            accessToken: $auth['access_token'],
            refreshToken: $auth['refresh_token'],
            expires: (int)($auth['expires_in'] ?? 3600),
        );

        $domainUrl = "https://" . $auth['domain'];

        // Проба идёт на той же изолированной шине, что и на install-странице. Токен из
        // ONAPPINSTALL — это будущий app-токен портала, но ставить его мог и рядовой
        // сотрудник, а гейт ещё впереди: до него запись в b24_apps не должна быть возможна.
        $probe = new InstallTokenProbe();

        $b24probe = (new ServiceBuilderFactory(eventDispatcher: $probe->eventDispatcher(), log: $logger))
            ->init(
                applicationProfile: $applicationProfile,
                authToken: $requestToken,
                bitrix24DomainUrl: $domainUrl,
                oauthServerUrl: $oauthServerUrl,
            );

        $profile = $b24probe->getMainScope()
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
            throw InstallerCannotOwnPortalException::notAnAdmin($memberId, $userId);
        }

        if ($userId === null) {
            throw InstallerCannotOwnPortalException::notIdentified($memberId);
        }

        $localAppAuth = new LocalAppAuth(
            authToken: $probe->tokenForStorage($requestToken),
            domainUrl: $auth['domain'],
            applicationToken: $auth['application_token'] ?? null,
            oauthServerUrl: $oauthServerUrl,
        );

        app(AppTokenWriter::class)->saveIfAllowed($localAppAuth, $memberId, $isAdmin, $userId);

        $b24 = (new ServiceBuilderFactory(eventDispatcher: resolve('appEvents'), log: $logger))
            ->init(
                applicationProfile: $applicationProfile,
                authToken: $probe->tokenForClient($requestToken),
                bitrix24DomainUrl: $domainUrl,
                oauthServerUrl: $oauthServerUrl,
            );

        app(AppSetupRunner::class)->run($b24, config('bitrix24.installers', []));
    }
}
