<?php

namespace X3Group\Bitrix24\Application\Local\Infrastructure\Database;

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Application\Local\Repository\LocalAppAuthRepositoryInterface;
use Bitrix24\SDK\Core\Response\DTO\RenewedAuthToken;
use X3Group\Bitrix24\Models\B24App;

readonly class AppAuthDatabaseStorage implements LocalAppAuthRepositoryInterface
{
    public function __construct(
        private string $memberId,
    )
    {

    }
    /**
     * @inheritDoc
     */
    public function getAuth(): LocalAppAuth
    {
        $b24app = B24App::query()
            ->where('member_id', $this->memberId)
            ->first();

        if (!$b24app) {
            throw new \Exception('Application is not installed');
        }

        return LocalAppAuth::initFromArray([
            'auth_token' => [
                'access_token' => $b24app->access_token,
                'refresh_token' => $b24app->refresh_token,
                'expires' => $b24app->expires,
            ],
            'domain_url' => "https://{$b24app->domain}",
            'application_token' => $b24app->application_token,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getApplicationToken(): ?string
    {
        $b24app = B24App::query()
            ->where('member_id', $this->memberId)
            ->first();

        return $b24app?->application_token ?? null;
    }

    /**
     * @inheritDoc
     */
    public function saveRenewedToken(RenewedAuthToken $renewedAuthToken): void
    {
        $b24app = B24App::query()
            ->where('member_id', $renewedAuthToken->memberId)
            ->first();

        if (!$b24app) {
            throw new \Exception('App token not found');
        }

        $b24app->access_token = $renewedAuthToken->authToken->accessToken;
        $b24app->refresh_token = $renewedAuthToken->authToken->refreshToken;
        $b24app->expires_in = $renewedAuthToken->authToken->expiresIn ?? 3600;
        $b24app->expires = $renewedAuthToken->authToken->expires;

        $b24app->save();
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

        $b24api = B24App::query()
            ->where('member_id', $this->memberId)
            ->first();

        if ($b24api === null) {
            B24App::query()
                ->create([
                    'access_token' => $localAppAuth->getAuthToken()->accessToken,
                    'refresh_token' => $localAppAuth->getAuthToken()->refreshToken,
                    'expires' => $expiresIn,
                    'expires_in' => $localAppAuth->getAuthToken()->expires,
                    'application_token' => $localAppAuth->getApplicationToken(),
                    'domain' => $localAppAuth->getDomainUrl(),
                    'member_id' => $this->memberId,
                ]);
        } else {
            $b24api->access_token = $localAppAuth->getAuthToken()->accessToken;
            $b24api->refresh_token = $localAppAuth->getAuthToken()->refreshToken;
            $b24api->expires = $expiresIn;
            $b24api->expires_in = $localAppAuth->getAuthToken()->expires;
            $b24api->application_token = $localAppAuth->getApplicationToken();
            $b24api->domain = $localAppAuth->getDomainUrl();

            $b24api->save();
        }
    }
}
