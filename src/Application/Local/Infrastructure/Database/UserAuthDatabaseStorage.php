<?php

namespace X3Group\Bitrix24\Application\Local\Infrastructure\Database;

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Application\Local\Repository\LocalAppAuthRepositoryInterface;
use Bitrix24\SDK\Core\Response\DTO\RenewedAuthToken;
use X3Group\Bitrix24\Application\Local\OauthServerUrlResolver;
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
            'oauth_server_url' => OauthServerUrlResolver::orDefault(
                B24App::query()->where('member_id', $b24user->member_id)->value('oauth_server_url')
            ),
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
     *
     * Правило 2: если обновляется токен того самого пользователя, который записан
     * владельцем портала в b24_apps.user_id, то же обновление уезжает и в b24_apps.
     * Иначе портал не трогаем — именно так рядовой сотрудник затирал админский
     * app-токен и ломал админские методы REST для клиента.
     *
     * Пара (member_id, user_id) для переноса берётся из тех же двух источников, по
     * которым только что нашли строку пользователя: member_id — из самого обновлённого
     * токена, user_id — из хранилища.
     *
     * Удерживаемый инвариант: обновляется тот портал, чей токен реально обновился, а не
     * тот, под который собрано хранилище. Сегодня разойтись они не могут — единственная
     * боевая проводка ('userEvents' в Bitrix24ServiceProvider) создаёт хранилище с тем же
     * member_id, что лежит в событии. Но эта связь держится на одной строке в провайдере,
     * а подмена на $this->memberId выглядит здесь совершенно естественно и молча
     * переписала бы чужой портал — поэтому инвариант закреплён тестом.
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

        app(AppTokenWriter::class)->propagateFromUser(
            $renewedAuthToken->memberId,
            $this->userId,
            $renewedAuthToken->authToken,
        );
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
