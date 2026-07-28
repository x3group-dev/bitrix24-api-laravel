<?php

namespace X3Group\Bitrix24\Tests\Feature;

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
 *
 * Обновлённый токен здесь собирается через {@see RenewedAuthToken::initFromArray} — тем же
 * методом, которым его собирает сам SDK на рефреше. Руками такой токен конструировать
 * нельзя: легко выдумать сочетание полей, которого SDK не производит.
 */
class InstallTokenProbeTest extends TestCase
{
    private const MEMBER = 'member-1';

    /** Срок жизни намеренно НЕ 3600: 3600 замаскировало бы захардкоженную константу. */
    private const LIFETIME = 7200;

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

    /**
     * Токен ровно в том виде, в каком его отдаёт рефреш SDK: expires — АБСОЛЮТНЫЙ
     * timestamp, expiresIn не заполнен (AuthToken::initFromArray принимает три поля).
     *
     * expires_in в payload есть намеренно: OAuth-сервер Битрикса присылает это поле
     * всегда, а сегодняшний AuthToken::initFromArray его игнорирует. Если убрать ключ
     * из фикстуры, пин ниже станет декоративным — он останется зелёным и в тот день,
     * когда SDK начнёт заполнять expiresIn, потому что заполнять будет нечем.
     */
    private function renewed(string $accessToken): AuthTokenRenewedEvent
    {
        return new AuthTokenRenewedEvent(RenewedAuthToken::initFromArray([
            'access_token' => $accessToken,
            'refresh_token' => $accessToken . '-refresh',
            'expires' => time() + self::LIFETIME,
            'expires_in' => self::LIFETIME,
            'member_id' => self::MEMBER,
            'client_endpoint' => 'https://portal.bitrix24.ru/rest/',
            'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
            'status' => 'S',
            'domain' => 'portal.bitrix24.ru',
        ]));
    }

    private function requestToken(): AuthToken
    {
        return new AuthToken(
            accessToken: 'from-request',
            refreshToken: 'from-request-refresh',
            expires: 3600,
        );
    }

    public function test_the_sdk_really_does_leave_expires_in_empty_on_a_refresh(): void
    {
        // Основание для всей конвертации ниже: если SDK начнёт заполнять expiresIn,
        // конвертация станет неверной, и узнать об этом надо здесь.
        $renewedToken = $this->renewed('renewed-on-probe')->getRenewedToken()->authToken;

        self::assertNull($renewedToken->expiresIn);
        self::assertGreaterThan(time(), $renewedToken->expires, 'expires обновлённого токена — абсолютный timestamp');
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
     * Поле expires у двух токенов означает разное: у токена установки это срок жизни,
     * у обновлённого — абсолютный timestamp. Проба обязана привести ко второму… то есть
     * к первому: к конвенции, которую понимает storage.
     */
    public function test_the_renewed_token_is_converted_to_the_placement_convention(): void
    {
        $installTokenProbe = new InstallTokenProbe();
        $installTokenProbe->eventDispatcher()->dispatch($this->renewed('renewed-on-probe'));

        $stored = $installTokenProbe->tokenForStorage($this->requestToken());

        self::assertEqualsWithDelta(
            self::LIFETIME,
            $stored->expires,
            2,
            'срок жизни взят не из токена — вероятно, подставлена константа',
        );
        self::assertNull($stored->expiresIn);
    }

    /**
     * Характеризующий тест: показывает, ПОЧЕМУ конвертация обязательна.
     *
     * AppAuthDatabaseStorage::save() смотрит на expiresIn, а он у обновлённого токена
     * пуст, — значит storage безусловно считает expires сроком жизни и пишет
     * now() + expires, то есть складывает два timestamp-а. RemoveUninstalledPortals
     * читает эту колонку как абсолютный timestamp, и такой портал не вычистится никогда.
     */
    public function test_storing_a_renewed_token_as_is_would_park_the_portal_in_the_far_future(): void
    {
        $renewedAsIs = $this->renewed('renewed-on-probe')->getRenewedToken()->authToken;

        (new AppAuthDatabaseStorage('naive-portal'))->save(self::localAppAuth($renewedAsIs));

        $naive = B24App::query()->where('member_id', 'naive-portal')->first();

        self::assertGreaterThan(
            time() + (10 * 365 * 86400),
            (int) $naive->expires,
            'два timestamp-а не сложились — характеризация устарела, перечитать save()',
        );

        // …а через пробу колонки встают правильно.
        $installTokenProbe = new InstallTokenProbe();
        $installTokenProbe->eventDispatcher()->dispatch($this->renewed('renewed-on-probe'));

        (new AppAuthDatabaseStorage('converted-portal'))
            ->save(self::localAppAuth($installTokenProbe->tokenForStorage($this->requestToken())));

        $converted = B24App::query()->where('member_id', 'converted-portal')->first();

        self::assertEqualsWithDelta(
            time() + self::LIFETIME,
            (int) $converted->expires,
            5,
            'колонка expires обязана быть моментом протухания токена, а не чем-то ещё',
        );
        self::assertEqualsWithDelta(
            self::LIFETIME,
            (int) $converted->expires_in,
            2,
            'колонка expires_in обязана быть сроком жизни токена',
        );
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
