<?php

namespace App\Http\Controllers\Bitrix24;

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Application\Requests\Events\OnApplicationInstall\OnApplicationInstall;
use Bitrix24\SDK\Application\Requests\Events\OnApplicationUninstall\OnApplicationUninstall;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use Bitrix24\SDK\Services\Main\Common\EventHandlerMetadata;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\View\View;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppAuthDatabaseStorage;

class InstallController extends Controller
{
    public function install(Request $request): View
    {
        try {
            /** @var EventDispatcherAdapter $eventDispatcher */
            $eventDispatcher = resolve('appEvents');
            $eventDispatcher->listen(AuthTokenRenewedEvent::class, function (AuthTokenRenewedEvent $authTokenRenewedEvent): void {
                /** @var AppAuthDatabaseStorage $appAuthStorage */
                $appAuthStorage = resolve(AppAuthDatabaseStorage::class, [
                    'memberId' => $authTokenRenewedEvent->getRenewedToken()->memberId,
                ]);
                $appAuthStorage->saveRenewedToken($authTokenRenewedEvent->getRenewedToken());
            });

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
                    'domain' => $request->input('DOMAIN')
                ]),
            );

            $currentB24UserId = $b24->getMainScope()
                ->main()
                ->getCurrentUserProfile()
                ->getUserProfile()
                ->ID;

            $authToken = new AuthToken(
                accessToken: $request->input('AUTH_ID'),
                refreshToken: $request->input('REFRESH_ID'),
                expires: (int)$request->input('AUTH_EXPIRES'),
            );

            $localAppAuth = new LocalAppAuth(
                authToken: $authToken,
                domainUrl: $request->input('DOMAIN'),
                applicationToken: null,
            );

            $memberId = $request->input('member_id');

            $storage = new AppAuthDatabaseStorage($memberId);
            $storage->save($localAppAuth);

            $b24->getMainScope()->eventManager()->unbindAllEventHandlers();

            $b24->getMainScope()->eventManager()->bindEventHandlers([
                new EventHandlerMetadata(
                    code: OnApplicationInstall::CODE,
                    handlerUrl: config('app.url') . '/events/onApplicationInstall',
                    userId: $currentB24UserId,
                ),
                new EventHandlerMetadata(
                    code: OnApplicationUninstall::CODE,
                    handlerUrl: config('app.url') . '/events/onApplicationUninstall',
                    userId: $currentB24UserId,
                ),
            ]);

            // your code here

            return view('b24api.install');
        } catch (\Throwable $e) {
            return view('b24api.install-fail');
        }
    }
}
