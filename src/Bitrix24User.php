<?php

namespace X3Group\Bitrix24;

use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use X3Group\Bitrix24\Models\B24User;

/**
 * Клиент для работы с API в контексте пользователя
 */
readonly class Bitrix24User
{
    public ServiceBuilder $api;

    private string $memberId;

    private int $userId;

    public function __construct(string $memberId, int $userId)
    {
        $this->memberId = $memberId;
        $this->userId = $userId;

        $factory = new ServiceBuilderFactory(
            eventDispatcher: resolve('userEvents', [
                'memberId' => $memberId,
                'userId' => $userId,
            ]),
            log: resolve('b24log', [
                'memberId' => $memberId
            ]),
        );

        $applicationProfile = new ApplicationProfile(
            clientId: config('bitrix24.client_id'),
            clientSecret: config('bitrix24.client_secret'),
            scope: Scope::initFromString(config('bitrix24.scope'))
        );

        /** @var B24User $b24user */
        $b24user = B24User::query()
            ->where('member_id', $memberId)
            ->where('user_id', $userId)
            ->first();

        if (empty($b24user)) {
            throw new \Exception('User not found');
        }

        $authToken = new AuthToken(
            accessToken: $b24user->access_token,
            refreshToken: $b24user->refresh_token,
            expires: $b24user->expires,
            expiresIn: $b24user->expires_in,
        );

        $this->api = $factory->init(
            applicationProfile: $applicationProfile,
            authToken: $authToken,
            bitrix24DomainUrl: "https://{$b24user->domain}",
        );
    }

    public function getMemberId(): string
    {
        return $this->memberId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public static function renewTokens(): void
    {
        if (!app()->isProduction()) {
            return;
        }

        $b24users = B24User::query()
            ->where('expires', '<=', time() - (20 * 3600 *24))
            ->where('error_update', '<', 10)
            ->get();

        /** @var B24User $b24user */
        foreach ($b24users as $b24user) {
            try {
                $b24 = new self($b24user->member_id, $b24user->user_id);
                $renewedToken = $b24->api->core->getApiClient()->getNewAuthToken();
            } catch (\Throwable $e) {
                logger()->error('renew token error', [
                    'member_id' => $b24user->member_id,
                    'domain' => $b24user->domain,
                    'user_id' => $b24user->user_id,
                    'error' => sprintf('%s:%s - %s', $e->getFile(), $e->getLine(), $e->getMessage()),
                ]);

                $b24user->error_update++;
                $b24user->save();

                continue;
            }

            $b24user->error_update = 0;
            $b24user->access_token = $renewedToken->authToken->accessToken;
            $b24user->refresh_token = $renewedToken->authToken->refreshToken;
            $b24user->expires_in = $renewedToken->authToken->expiresIn ?? 3600;
            $b24user->expires = $renewedToken->authToken->expires;
            $b24user->save();

            logger()->debug('renew token success', [
                'member_id' => $b24user->member_id,
                'domain' => $b24user->domain,
                'user_id' => $b24user->user_id,
            ]);
        }
    }
}
