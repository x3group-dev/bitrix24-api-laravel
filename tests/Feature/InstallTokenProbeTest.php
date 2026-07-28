<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Bitrix24\SDK\Application\ApplicationStatus;
use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Response\DTO\RenewedAuthToken;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use X3Group\Bitrix24\Application\Install\InstallTokenProbe;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppAuthDatabaseStorage;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Проба установки: шина, которой пользуется вызов «кто ставит приложение?».
 *
 * Проба идёт ДО проверки админства, поэтому её шина не должна уметь писать в b24_apps.
 * Но и молча терять обновлённый токен нельзя: Битрикс отзывает refresh_token сразу после
 * обмена, так что записать в b24_apps исходный токен после рефреша значит положить туда
 * заведомо мёртвый refresh_token.
 */
class InstallTokenProbeTest extends TestCase
{
    private const MEMBER = 'member-1';

    protected function setUp(): void
    {
        parent::setUp();

        B24App::query()->create([
            'member_id' => self::MEMBER,
            'domain' => 'portal.bitrix24.ru',
            'access_token' => 'app-access',
            'refresh_token' => 'app-refresh',
            'expires' => time() + 3600,
            'expires_in' => 3600,
            'application_token' => 'app-token',
            'user_id' => 8,
        ]);
    }

    private function renewed(string $accessToken): AuthTokenRenewedEvent
    {
        return new AuthTokenRenewedEvent(new RenewedAuthToken(
            authToken: new AuthToken(
                accessToken: $accessToken,
                refreshToken: $accessToken . '-refresh',
                expires: time() + 3600,
                expiresIn: 3600,
            ),
            memberId: self::MEMBER,
            clientEndpoint: 'https://portal.bitrix24.ru/rest/',
            serverEndpoint: 'https://oauth.bitrix24.tech/rest/',
            applicationStatus: ApplicationStatus::subscription(),
            domain: 'portal.bitrix24.ru',
        ));
    }

    private function requestToken(): AuthToken
    {
        return new AuthToken(
            accessToken: 'from-request',
            refreshToken: 'from-request-refresh',
            expires: 3600,
        );
    }

    public function test_a_refresh_during_the_probe_does_not_reach_b24_apps(): void
    {
        $installTokenProbe = new InstallTokenProbe();

        $installTokenProbe->eventDispatcher()->dispatch($this->renewed('renewed-on-probe'));

        self::assertSame(
            'app-access',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'токен, обновлённый до проверки админства, уехал в b24_apps мимо AppTokenWriter',
        );
    }

    public function test_a_refresh_during_the_probe_is_not_lost_either(): void
    {
        $installTokenProbe = new InstallTokenProbe();

        $installTokenProbe->eventDispatcher()->dispatch($this->renewed('renewed-on-probe'));

        self::assertSame(
            'renewed-on-probe',
            $installTokenProbe->tokenForClient($this->requestToken())->accessToken,
            'рабочий клиент доигрывает установку отозванным токеном',
        );
        self::assertSame(
            'renewed-on-probe',
            $installTokenProbe->tokenForStorage($this->requestToken())->accessToken,
            'в b24_apps уехал отозванный refresh_token — портал сломается до переустановки',
        );
    }

    public function test_without_a_refresh_the_request_token_is_used_unchanged(): void
    {
        $installTokenProbe = new InstallTokenProbe();
        $requestToken = $this->requestToken();

        self::assertSame($requestToken, $installTokenProbe->tokenForClient($requestToken));
        self::assertSame(
            $requestToken,
            $installTokenProbe->tokenForStorage($requestToken),
            'на обычной установке (рефреша не было) токен обязан доехать нетронутым',
        );
    }

    /**
     * Обновлённый токен приходит в другой «конвенции», чем токен из запроса установки:
     * у первого expires — абсолютный timestamp, а срок жизни лежит в expiresIn; у второго
     * expires — сам срок жизни, а expiresIn пуст. Проба обязана привести к второй.
     */
    public function test_the_renewed_token_is_converted_to_the_placement_convention(): void
    {
        $installTokenProbe = new InstallTokenProbe();
        $installTokenProbe->eventDispatcher()->dispatch($this->renewed('renewed-on-probe'));

        $stored = $installTokenProbe->tokenForStorage($this->requestToken());

        self::assertSame(3600, $stored->expires, 'expires должен стать сроком жизни, а не timestamp-ом');
        self::assertNull($stored->expiresIn, 'непустой expiresIn заставит storage поменять колонки местами');
    }

    /**
     * Характеризующий тест: показывает, ПОЧЕМУ конвертация обязательна.
     *
     * AppAuthDatabaseStorage::save() различает конвенции по expiresIn и для непустого
     * expiresIn кладёт срок жизни в колонку expires, а timestamp — в expires_in, то есть
     * меняет их местами. Отдать туда обновлённый токен «как есть» — записать портал с
     * перепутанным сроком годности.
     */
    public function test_storing_a_renewed_token_as_is_would_swap_the_expiry_columns(): void
    {
        $renewedAsIs = new AuthToken(
            accessToken: 'renewed-on-probe',
            refreshToken: 'renewed-on-probe-refresh',
            expires: time() + 3600,
            expiresIn: 3600,
        );

        (new AppAuthDatabaseStorage('naive-portal'))->save(self::localAppAuth($renewedAsIs));

        $naive = B24App::query()->where('member_id', 'naive-portal')->first();

        self::assertSame(3600, (int) $naive->expires, 'колонка expires получила срок жизни вместо timestamp');
        self::assertGreaterThan(1_000_000_000, (int) $naive->expires_in, 'колонка expires_in получила timestamp');

        // …а через пробу колонки встают правильно.
        $installTokenProbe = new InstallTokenProbe();
        $installTokenProbe->eventDispatcher()->dispatch($this->renewed('renewed-on-probe'));

        (new AppAuthDatabaseStorage('converted-portal'))
            ->save(self::localAppAuth($installTokenProbe->tokenForStorage($this->requestToken())));

        $converted = B24App::query()->where('member_id', 'converted-portal')->first();

        self::assertGreaterThan(1_000_000_000, (int) $converted->expires, 'expires обязан быть timestamp-ом');
        self::assertSame(3600, (int) $converted->expires_in, 'expires_in обязан быть сроком жизни');
        self::assertSame('renewed-on-probe', $converted->access_token);
    }

    private static function localAppAuth(AuthToken $authToken): LocalAppAuth
    {
        return new LocalAppAuth(
            authToken: $authToken,
            domainUrl: 'portal.bitrix24.ru',
            applicationToken: 'app-token',
            oauthServerUrl: 'https://oauth.bitrix.info',
        );
    }
}
