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
}
