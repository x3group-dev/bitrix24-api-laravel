<?php

namespace X3Group\Bitrix24;

use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Symfony\Component\EventDispatcher\EventDispatcher;
use X3Group\Bitrix24\Models\B24App;

/**
 * Клиент для работы с API в контексте приложения
 */
readonly class Bitrix24App
{
    public ServiceBuilder $api;

    private string $memberId;

    public function __construct(string $memberId)
    {
        $this->memberId = $memberId;

        $applicationProfile = new ApplicationProfile(
            clientId: config('bitrix24.client_id'),
            clientSecret: config('bitrix24.client_secret'),
            scope: Scope::initFromString(config('bitrix24.scope'))
        );

        $b24api = B24App::query()
            ->where('member_id', $memberId)
            ->first();

        $authToken = new AuthToken(
            accessToken: $b24api->access_token,
            refreshToken: $b24api->refresh_token,
            expires: $b24api->expires,
            expiresIn: $b24api->expires_in,
        );

        /** @var EventDispatcher $eventDispatcher */
        $eventDispatcher = resolve('appEvents');

        $app = new ServiceBuilderFactory(
            eventDispatcher: $eventDispatcher,
            log: resolve('b24log', [
                'memberId' => $memberId
            ]),
        );

        $this->api = $app->init(
            applicationProfile: $applicationProfile,
            authToken: $authToken,
            bitrix24DomainUrl: "https://{$b24api->domain}",
        );
    }

    public function getMemberId(): string
    {
        return $this->memberId;
    }
}
