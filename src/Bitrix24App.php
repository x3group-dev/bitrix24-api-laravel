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

    public static function renewTokens(): void
    {
        if (!app()->isProduction()) {
            return;
        }

        $b24apps = B24App::query()
            ->where('expires', '<=', time() - (20 * 3600 *24))
            ->where('error_update', '<', 10)
            ->get();

        /** @var B24App $b24app */
        foreach ($b24apps as $b24app) {
            try {
                $b24 = new self($b24app->member_id);
                $renewedToken = $b24->api->core->getApiClient()->getNewAuthToken();
            } catch (\Throwable $e) {
                logger()->error('renew token error', [
                    'member_id' => $b24app->member_id,
                    'domain' => $b24app->domain,
                    'error' => sprintf('%s:%s - %s', $e->getFile(), $e->getLine(), $e->getMessage()),
                ]);

                $b24app->error_update++;
                $b24app->save();

                continue;
            }

            $b24app->error_update = 0;
            $b24app->access_token = $renewedToken->authToken->accessToken;
            $b24app->refresh_token = $renewedToken->authToken->refreshToken;
            $b24app->expires_in = $renewedToken->authToken->expiresIn ?? 3600;
            $b24app->expires = $renewedToken->authToken->expires;
            $b24app->save();

            logger()->debug('renew token success', [
                'member_id' => $b24app->member_id,
                'domain' => $b24app->domain,
            ]);
        }
    }
}
