<?php

namespace X3Group\Bitrix24\Application\SystemUser;

use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Core\Response\DTO\RenewedAuthToken;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;
use X3Group\Bitrix24\Core\B24ServiceBuilderFactory;

/**
 * Обмен refresh-токена на новую пару на сервере OAuth Битрикса.
 *
 * Единственная точка, где приём ONAPPUSERREADY обращается в сеть; при проверке
 * подменяется через контейнер.
 */
class SystemUserTokenExchange
{
    public function exchange(string $memberId, AuthToken $token, string $domainUrl, string $oauthServerUrl): RenewedAuthToken
    {
        $applicationProfile = new ApplicationProfile(
            clientId: config('bitrix24.client_id'),
            clientSecret: config('bitrix24.client_secret'),
            scope: Scope::initFromString(config('bitrix24.scope')),
        );

        // Диспетчер без слушателей: слушатель 'appEvents' записал бы обновлённый токен
        // в b24_apps до проверки member_id.
        $serviceBuilder = (new B24ServiceBuilderFactory(
            eventDispatcher: new EventDispatcherAdapter(),
            log: resolve('b24log', ['memberId' => $memberId, 'domain' => $domainUrl]),
        ))->init(
            applicationProfile: $applicationProfile,
            authToken: $token,
            bitrix24DomainUrl: $domainUrl,
            oauthServerUrl: $oauthServerUrl,
        );

        return $serviceBuilder->core->getApiClient()->getNewAuthToken();
    }
}
