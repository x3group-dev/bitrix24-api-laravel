<?php

namespace X3Group\Bitrix24\Application\Local\Infrastructure\Database;

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Application\Local\Repository\LocalAppAuthRepositoryInterface;
use Bitrix24\SDK\Core\Response\DTO\RenewedAuthToken;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;

readonly class UserAuthDatabaseStorage implements LocalAppAuthRepositoryInterface
{
    public function __construct(
        private string $memberId,
        private int $userId,
    )
    {

    }
    /**
     * @inheritDoc
     */
    public function getAuth(): LocalAppAuth
    {
        $b24user = B24User::query()
            ->with(B24App::class)
            ->where('member_id', $this->memberId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$b24user) {
            throw new \Exception('User token not found');
        }

        return LocalAppAuth::initFromArray([
            'auth_token' => [
                'access_token' => $b24user->access_token,
                'refresh_token' => $b24user->refresh_token,
                'expires' => $b24user->expires,
            ],
            'domain_url' => "https://{$b24user->domain}",
            'application_token' => $b24user->b24app()->application_token,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getApplicationToken(): ?string
    {
        $b24user = B24User::query()
            ->with(B24App::class)
            ->where('member_id', $this->memberId)
            ->where('user_id', $this->userId)
            ->first();

        return $b24user?->b24app()->application_token ?? null;
    }

    /**
     * @inheritDoc
     */
    public function saveRenewedToken(RenewedAuthToken $renewedAuthToken): void
    {
        $b24user = B24User::query()
            ->where('member_id', $renewedAuthToken->memberId)
            ->where('user_id', $this->userId)
            ->first();

        if (!$b24user) {
            throw new \Exception('User token not found');
        }

        $b24user->access_token = $renewedAuthToken->authToken->accessToken;
        $b24user->refresh_token = $renewedAuthToken->authToken->refreshToken;
        $b24user->expires_in = $renewedAuthToken->authToken->expiresIn ?? 3600;
        $b24user->expires = $renewedAuthToken->authToken->expires;

        $b24user->save();
    }

    /**
     * @inheritDoc
     */
    public function save(LocalAppAuth $localAppAuth): void
    {
        $expiresIn = $localAppAuth->getAuthToken()->expiresIn;

        if ($expiresIn === null) {
            $expiresIn = now();
            $expiresIn->addSeconds($localAppAuth->getAuthToken()->expires);
            $expiresIn = $expiresIn->timestamp;
        }

        $b24api = B24User::query()
            ->where('member_id', $this->memberId)
            ->where('user_id', $this->userId)
            ->first();

        if ($b24api === null) {
            B24User::query()
                ->create([
                    'access_token' => $localAppAuth->getAuthToken()->accessToken,
                    'refresh_token' => $localAppAuth->getAuthToken()->refreshToken,
                    'expires' => $expiresIn,
                    'expires_in' => $localAppAuth->getAuthToken()->expires,
                    'domain' => $localAppAuth->getDomainUrl(),
                    'member_id' => $this->memberId,
                ]);
        } else {
            $b24api->access_token = $localAppAuth->getAuthToken()->accessToken;
            $b24api->refresh_token = $localAppAuth->getAuthToken()->refreshToken;
            $b24api->expires = $expiresIn;
            $b24api->expires_in = $localAppAuth->getAuthToken()->expires;
            $b24api->domain = $localAppAuth->getDomainUrl();

            $b24api->save();
        }
    }
}
